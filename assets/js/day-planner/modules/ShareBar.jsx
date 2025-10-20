import React from "react";
import PropTypes from "prop-types";

function ShareBar({ shareUrl, onShare }) {
  return (
    <section className="sbdp-day-planner__share">
      <h4>Share</h4>
      <input type="text" readOnly value={shareUrl || ""} />
      <button type="button" onClick={onShare}>
        Share plan
      </button>
    </section>
  );
}

ShareBar.propTypes = {
  shareUrl: PropTypes.string,
  onShare: PropTypes.func.isRequired,
};

ShareBar.defaultProps = {
  shareUrl: "",
};

export default ShareBar;
