import React from "react";
import PropTypes from "prop-types";

function AIRecommendations({ data }) {
  if (!data) {
    return null;
  }

  return (
    <div className="sbdp-ai-recommendations">
      <h4>AI Suggestions</h4>
      <ul>
        <li>
          Peak Day: {data.peak_day ? `${data.peak_day.date} (${data.peak_day.count})` : "Insufficient data"}
        </li>
        <li>
          Best Slot: {data.best_slot ? `${data.best_slot.slot}` : "Insufficient data"}
        </li>
      </ul>
    </div>
  );
}

AIRecommendations.propTypes = {
  data: PropTypes.shape({
    peak_day: PropTypes.shape({
      date: PropTypes.string,
      count: PropTypes.number,
    }),
    best_slot: PropTypes.shape({
      slot: PropTypes.string,
    }),
  }),
};

AIRecommendations.defaultProps = {
  data: null,
};

export default AIRecommendations;
