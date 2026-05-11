import React from "react";
import PropTypes from "prop-types";

import ExportButton from "./ExportButton";

function QuickActions({
  onAdd,
  onExport,
  viewMode,
  onViewModeChange,
  calendarView,
  onCalendarViewChange,
  loading,
}) {
  const activateList = () => {
    onViewModeChange("list");
  };

  const activateCalendar = (mode) => {
    if (viewMode !== "calendar") {
      onViewModeChange("calendar");
    }
    onCalendarViewChange(mode);
  };

  return (
    <div className="sbdp-quick-actions">
      <div className="sbdp-quick-actions__left">
        <button type="button" className="button button-primary" onClick={onAdd}>
          + New Booking
        </button>
        <div className="sbdp-quick-actions__toggle">
          <button
            type="button"
            className={viewMode === "list" ? "button button-secondary is-active" : "button button-secondary"}
            onClick={activateList}
          >
            List
          </button>
          <button
            type="button"
            className={
              viewMode === "calendar" && calendarView === "day"
                ? "button button-secondary is-active"
                : "button button-secondary"
            }
            onClick={() => activateCalendar("day")}
          >
            Day
          </button>
          <button
            type="button"
            className={
              viewMode === "calendar" && calendarView === "week"
                ? "button button-secondary is-active"
                : "button button-secondary"
            }
            onClick={() => activateCalendar("week")}
          >
            Week
          </button>
        </div>
      </div>
      <div className="sbdp-quick-actions__right">
        <ExportButton onExport={onExport} disabled={loading} />
      </div>
    </div>
  );
}

QuickActions.propTypes = {
  onAdd: PropTypes.func.isRequired,
  onExport: PropTypes.func.isRequired,
  viewMode: PropTypes.string.isRequired,
  onViewModeChange: PropTypes.func.isRequired,
  calendarView: PropTypes.oneOf(["day", "week"]).isRequired,
  onCalendarViewChange: PropTypes.func.isRequired,
  loading: PropTypes.bool.isRequired,
};

export default QuickActions;
