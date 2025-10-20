import React from "react";
import PropTypes from "prop-types";

function ConflictWarnings({ conflicts }) {
  if (!conflicts || conflicts.length === 0) {
    return null;
  }

  return (
    <aside className="sbdp-day-planner__conflicts">
      <h4>Conflicts</h4>
      <ul>
        {conflicts.map((conflict, index) => (
          <li key={index}>
            {conflict.reason} — {conflict.day || conflict.day_index}
          </li>
        ))}
      </ul>
    </aside>
  );
}

ConflictWarnings.propTypes = {
  conflicts: PropTypes.arrayOf(PropTypes.object),
};

ConflictWarnings.defaultProps = {
  conflicts: [],
};

export default ConflictWarnings;
