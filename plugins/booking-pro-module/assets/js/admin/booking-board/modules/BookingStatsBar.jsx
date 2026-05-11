import React from "react";
import PropTypes from "prop-types";

import AIRecommendations from "./AIRecommendations";

const numberFormatter = typeof Intl !== "undefined" ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }) : null;

function formatInteger(value) {
  if (!Number.isFinite(value)) {
    return "–";
  }

  return numberFormatter ? numberFormatter.format(value) : String(Math.trunc(value));
}

function formatCurrency(amount, currency) {
  if (!Number.isFinite(amount)) {
    return "–";
  }

  if (typeof Intl !== "undefined" && Intl.NumberFormat) {
    try {
      return new Intl.NumberFormat(undefined, { style: "currency", currency: currency || "EUR", maximumFractionDigits: 0 }).format(amount);
    } catch (error) {
      // fallback below
    }
  }

  return `${currency || "EUR"} ${amount.toFixed(0)}`;
}

function formatDateLabel(date) {
  if (!date) {
    return "";
  }

  try {
    const formatter = new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric" });
    return formatter.format(new Date(`${date}T00:00:00`));
  } catch (error) {
    return date;
  }
}

function Sparkline({ data }) {
  if (!Array.isArray(data) || data.length === 0) {
    return null;
  }

  const width = Math.max(140, (data.length - 1) * 28);
  const height = 44;
  const maxValue = Math.max(...data, 1);
  const minValue = Math.min(...data, 0);
  const amplitude = Math.max(maxValue - minValue, 1);

  const points = data.map((value, index) => {
    const x = (index / Math.max(data.length - 1, 1)) * (width - 8) + 4;
    const normalized = (value - minValue) / amplitude;
    const y = height - normalized * (height - 8) - 4;

    return `${x},${y}`;
  });

  const lastPoint = points[points.length - 1].split(",").map(Number);

  return (
    <svg className="sbdp-booking-stats__sparkline" width={width} height={height} viewBox={`0 0 ${width} ${height}`} role="presentation" focusable="false">
      <polyline points={points.join(" ")} fill="none" stroke="#3b82f6" strokeWidth="2" strokeLinecap="round" />
      {lastPoint ? <circle cx={lastPoint[0]} cy={lastPoint[1]} r="4" fill="#1d4ed8" stroke="#ffffff" strokeWidth="2" /> : null}
    </svg>
  );
}

Sparkline.propTypes = {
  data: PropTypes.arrayOf(PropTypes.number),
};

Sparkline.defaultProps = {
  data: [],
};

function BookingStatsBar({ stats, metrics }) {
  if (!stats && !metrics) {
    return null;
  }

  const currency = metrics?.revenue?.currency || "EUR";
  const cards = [
    {
      id: "total",
      label: "Total Bookings",
      value: formatInteger(stats?.total),
    },
    {
      id: "today",
      label: "Departures Today",
      value: formatInteger(metrics?.today ?? stats?.today),
    },
    {
      id: "next_7_days",
      label: "Next 7 Days",
      value: formatInteger(metrics?.next_7_days),
    },
    {
      id: "overdue_payments",
      label: "Overdue Payments",
      value: formatInteger(metrics?.overdue_payments ?? stats?.pending),
    },
    {
      id: "revenue_today",
      label: "Revenue Today",
      value: formatCurrency(metrics?.revenue?.today?.amount ?? stats?.revenue_today, currency),
      html: metrics?.revenue?.today?.formatted,
    },
  ];

  const sparkline = Array.isArray(metrics?.trend?.sparkline) ? metrics.trend.sparkline : [];
  const trendSeries = Array.isArray(metrics?.trend?.series) ? metrics.trend.series : [];
  const trendPeriod = metrics?.trend?.period || null;
  const trendLatest = sparkline.length > 0 ? sparkline[sparkline.length - 1] : null;

  const topOutlets = Array.isArray(metrics?.top_outlets) ? metrics.top_outlets : [];

  return (
    <div className="sbdp-booking-stats">
      <div className="sbdp-booking-stats__cards">
        {cards.map((card) => (
          <div key={card.id} className="sbdp-booking-stats__card">
            <span className="sbdp-booking-stats__label">{card.label}</span>
            {card.html ? (
              <strong className="sbdp-booking-stats__value" dangerouslySetInnerHTML={{ __html: card.html }} />
            ) : (
              <strong className="sbdp-booking-stats__value">{card.value}</strong>
            )}
          </div>
        ))}
      </div>
      <div className="sbdp-booking-stats__insights">
        {sparkline.length > 0 ? (
          <div className="sbdp-booking-stats__trend">
            <div className="sbdp-booking-stats__trend-header">
              <span className="sbdp-booking-stats__trend-title">7-day Trend</span>
              {Number.isFinite(trendLatest) ? (
                <span className="sbdp-booking-stats__trend-value">{formatInteger(trendLatest)} today</span>
              ) : null}
            </div>
            <Sparkline data={sparkline} />
            {trendPeriod ? (
              <div className="sbdp-booking-stats__trend-range">
                <span>{formatDateLabel(trendPeriod.start)}</span>
                <span>{formatDateLabel(trendPeriod.end)}</span>
              </div>
            ) : null}
          </div>
        ) : null}
        {topOutlets.length > 0 ? (
          <div className="sbdp-booking-stats__outlets">
            <span className="sbdp-booking-stats__outlets-title">Top Outlets</span>
            <ul className="sbdp-booking-stats__outlets-list">
              {topOutlets.map((outlet) => (
                <li key={outlet.id || outlet.label} className="sbdp-booking-stats__outlets-item">
                  <span className="sbdp-booking-stats__outlets-name">{outlet.label || "Unassigned"}</span>
                  <span className="sbdp-booking-stats__outlets-meta">
                    {formatInteger(outlet.count)} | {formatCurrency(outlet.revenue_total, currency)}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
      <AIRecommendations data={stats?.ai ?? metrics?.raw?.ai ?? null} />
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
  metrics: PropTypes.shape({
    today: PropTypes.number,
    next_7_days: PropTypes.number,
    overdue_payments: PropTypes.number,
    check_ins_due: PropTypes.number,
    revenue: PropTypes.shape({
      currency: PropTypes.string,
      today: PropTypes.shape({
        amount: PropTypes.number,
        formatted: PropTypes.string,
      }),
      total: PropTypes.shape({
        amount: PropTypes.number,
        formatted: PropTypes.string,
      }),
    }),
    trend: PropTypes.shape({
      period: PropTypes.shape({
        start: PropTypes.string,
        end: PropTypes.string,
      }),
      series: PropTypes.arrayOf(
        PropTypes.shape({
          date: PropTypes.string,
          count: PropTypes.number,
        })
      ),
      sparkline: PropTypes.arrayOf(PropTypes.number),
    }),
    top_outlets: PropTypes.arrayOf(
      PropTypes.shape({
        id: PropTypes.string,
        label: PropTypes.string,
        count: PropTypes.number,
        revenue_total: PropTypes.number,
      })
    ),
    raw: PropTypes.object,
  }),
};

BookingStatsBar.defaultProps = {
  stats: null,
  metrics: null,
};

export default BookingStatsBar;





