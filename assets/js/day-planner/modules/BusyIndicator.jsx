import React from "react";
import PropTypes from "prop-types";

function BusyIndicator({ busy, label }) {
  if (!busy) {
    return null;
  }

  return (
    <div className="sbdp-busy-indicator" aria-live="polite">
      <span className="spinner" />
      <span>{label}</span>
    </div>
  );
}

BusyIndicator.propTypes = {
  busy: PropTypes.bool.isRequired,
  label: PropTypes.string,
};

BusyIndicator.defaultProps = {
  label: "Loading…",
};

export default BusyIndicator;
