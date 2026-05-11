import React from "react";
import PropTypes from "prop-types";

export default function Flourish({ className }) {
  return (
    <svg
      className={className}
      viewBox="0 0 240 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <path
        d="M4 12C24 12 24 4 44 4C64 4 64 20 84 20C104 20 104 12 120 12C136 12 136 20 156 20C176 20 176 4 196 4C216 4 216 12 236 12"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
      />
      <circle cx="120" cy="12" r="3.2" fill="currentColor" />
    </svg>
  );
}

Flourish.propTypes = {
  className: PropTypes.string,
};

Flourish.defaultProps = {
  className: "",
};
