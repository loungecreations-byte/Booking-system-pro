import React from "react";
import PropTypes from "prop-types";

function ExportButton({ onExport, disabled }) {
  return (
    <button type="button" className="button" onClick={onExport} disabled={disabled}>
      Export
    </button>
  );
}

ExportButton.propTypes = {
  onExport: PropTypes.func.isRequired,
  disabled: PropTypes.bool,
};

ExportButton.defaultProps = {
  disabled: false,
};

export default ExportButton;
