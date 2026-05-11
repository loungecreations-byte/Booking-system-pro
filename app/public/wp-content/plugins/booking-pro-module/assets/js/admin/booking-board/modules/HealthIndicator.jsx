import React from "react";
import PropTypes from "prop-types";
import { __ } from "@wordpress/i18n";

const STATUS_COLORS = {
  ok: "#16a34a",
  degraded: "#eab308",
  down: "#ef4444",
};

function HealthIndicator({ status, details }) {
  const color = STATUS_COLORS[status] || STATUS_COLORS.ok;
  const title =
    status === "ok"
      ? __("Alle services bereikbaar", "sbdp")
      : status === "degraded"
        ? __("Sommige services reageren traag of niet", "sbdp")
        : __("Services onbereikbaar", "sbdp");

  const tooltip = details.length
    ? details
        .map((item) => `${item.label}: ${item.message || item.status}`)
        .join("\n")
    : title;

  return (
    <div className="sbdp-health-indicator" title={tooltip}>
      <span className="sbdp-health-indicator__label">{__("Status", "sbdp")}</span>
      <span
        className="sbdp-health-indicator__dot"
        style={{ backgroundColor: color }}
        aria-hidden="true"
      />
    </div>
  );
}

HealthIndicator.propTypes = {
  status: PropTypes.oneOf(["ok", "degraded", "down"]).isRequired,
  details: PropTypes.arrayOf(
    PropTypes.shape({
      label: PropTypes.string.isRequired,
      status: PropTypes.number,
      message: PropTypes.string,
    })
  ),
};

HealthIndicator.defaultProps = {
  details: [],
};

export default HealthIndicator;
