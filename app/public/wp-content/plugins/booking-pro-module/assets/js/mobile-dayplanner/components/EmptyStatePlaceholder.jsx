import React from "react";
import PropTypes from "prop-types";

export default function EmptyStatePlaceholder({ message }) {
  return <p className="sbdp-empty-state">{message}</p>;
}

EmptyStatePlaceholder.propTypes = {
  message: PropTypes.string,
};

EmptyStatePlaceholder.defaultProps = {
  message: "Nog niets gepland.",
};

