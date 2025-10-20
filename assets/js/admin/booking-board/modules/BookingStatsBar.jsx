import React from "react";
import PropTypes from "prop-types";

import AIRecommendations from "./AIRecommendations";

function BookingStatsBar({ stats }) {
  if (!stats) {
    return null;
  }

  const cards = [
    { id: "total", label: "Total", value: stats.total },
    { id: "paid", label: "Paid", value: stats.paid },
    { id: "pending", label: "Pending", value: stats.pending },
    { id: "cancelled", label: "Cancelled", value: stats.cancelled },
    { id: "revenue_today", label: "Revenue Today", value: stats.revenue_today.toFixed(2) },
  ];

  return (
    <div className="sbdp-booking-stats">
      <div className="sbdp-booking-stats__cards">
        {cards.map((card) => (
          <div key={card.id} className="sbdp-booking-stats__card">
            <span className="sbdp-booking-stats__label">{card.label}</span>
            <strong className="sbdp-booking-stats__value">{card.value}</strong>
          </div>
        ))}
      </div>
      <AIRecommendations data={stats.ai} />
    </div>
  );
}

BookingStatsBar.propTypes = {
  stats: PropTypes.shape({
    total: PropTypes.number,
    paid: PropTypes.number,
    pending: PropTypes.number,
    cancelled: PropTypes.number,
    revenue_today: PropTypes.number,
    ai: PropTypes.object,
  }),
};

BookingStatsBar.defaultProps = {
  stats: null,
};

export default BookingStatsBar;
