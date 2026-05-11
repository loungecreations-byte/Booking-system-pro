import React from "react";
import PropTypes from "prop-types";
import { __ } from "@wordpress/i18n";

function InlineStatusEdit({ value, color, onChange, options }) {
  const handleChange = (event) => {
    onChange(event.target.value);
  };

  const items =
    options.length > 0
      ? options
      : [
          { value: "created", label: __("Created", "sbdp") },
          { value: "requested", label: __("Requested", "sbdp") },
          { value: "captured", label: __("Captured", "sbdp") },
          { value: "pending", label: __("Pending", "sbdp") },
          { value: "paid", label: __("Paid", "sbdp") },
          { value: "completed", label: __("Completed", "sbdp") },
          { value: "cancelled", label: __("Cancelled", "sbdp") },
        ];

  return (
    <span className="sbdp-inline-status-edit" style={{ color }}>
      <select value={value} onChange={handleChange}>
        {items.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </span>
  );
}

InlineStatusEdit.propTypes = {
  value: PropTypes.string.isRequired,
  color: PropTypes.string,
  onChange: PropTypes.func.isRequired,
  options: PropTypes.arrayOf(
    PropTypes.shape({
      value: PropTypes.string.isRequired,
      label: PropTypes.string.isRequired,
    })
  ),
};

InlineStatusEdit.defaultProps = {
  color: "",
  options: [],
};

export default InlineStatusEdit;
