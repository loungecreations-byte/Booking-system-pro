import React from "react";
import PropTypes from "prop-types";

const LABELS = {
  total: "Totaal",
  booking_fee: "Boekingskosten",
  participant_pp: "Prijs per deelnemer",
  tax: "Btw",
};

const formatLabel = (key) => {
  if (LABELS[key]) {
    return LABELS[key];
  }

  return key
    .split("_")
    .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
    .join(" ");
};

const formatCurrency = (value, currency) => {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return value;
  }

  try {
    return new Intl.NumberFormat("nl-NL", {
      style: "currency",
      currency: currency || "EUR",
      minimumFractionDigits: 2,
    }).format(value);
  } catch (error) {
    const symbol = currency === "EUR" || !currency ? "€" : `${currency} `;
    return `${symbol}${value.toFixed(2)}`;
  }
};

function PriceSummary({ totals, currency }) {
  const entries = Object.entries(totals || {}).filter(
    ([key, value]) => typeof value === "number" && !Number.isNaN(value)
  );

  return (
    <section className="sbdp-day-planner__price-summary">
      <h4>Prijsindicatie</h4>
      {entries.length === 0 ? (
        <p>Voeg activiteiten toe om een prijsopbouw te zien.</p>
      ) : (
        <dl>
          {entries.map(([key, value]) => (
            <div key={key} className="sbdp-day-planner__price-summary-row">
              <dt>{formatLabel(key)}</dt>
              <dd>{formatCurrency(value, currency)}</dd>
            </div>
          ))}
        </dl>
      )}
      <p className="sbdp-day-planner__price-summary-disclaimer">
        Prijzen zijn indicatief en exclusief eventuele kortingen of maatwerk.
      </p>
    </section>
  );
}

PriceSummary.propTypes = {
  totals: PropTypes.object.isRequired,
  currency: PropTypes.string,
};

PriceSummary.defaultProps = {
  currency: "EUR",
};

export default PriceSummary;
