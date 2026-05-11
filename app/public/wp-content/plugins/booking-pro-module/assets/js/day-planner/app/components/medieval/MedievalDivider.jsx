import React from "react";
import PropTypes from "prop-types";

import Flourish from "./Flourish.jsx";

export default function MedievalDivider({ label }) {
  return (
    <div className="sbdp-medieval-divider" role="presentation">
      <Flourish className="sbdp-medieval-divider__flourish" />
      {label ? <span className="sbdp-medieval-divider__label">{label}</span> : null}
      <Flourish className="sbdp-medieval-divider__flourish sbdp-medieval-divider__flourish--mirror" />
    </div>
  );
}

MedievalDivider.propTypes = {
  label: PropTypes.string,
};

MedievalDivider.defaultProps = {
  label: "",
};
