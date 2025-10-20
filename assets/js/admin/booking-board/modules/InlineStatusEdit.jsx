import React from "react";
import PropTypes from "prop-types";

const OPTIONS = ["created", "requested", "captured", "pending", "paid", "completed", "cancelled"];

function InlineStatusEdit({ value, color, onChange }) {
  const handleChange = (event) => {
    onChange(event.target.value);
  };

  return (
    <span className="sbdp-inline-status-edit" style={{ color }}>
      <select value={value} onChange={handleChange}>
        {OPTIONS.map((option) => (
          <option key={option} value={option}>
            {option.charAt(0).toUpperCase() + option.slice(1)}
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
};

InlineStatusEdit.defaultProps = {
  color: "",
};

export default InlineStatusEdit;
