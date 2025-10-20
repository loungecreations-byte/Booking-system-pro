import React from "react";
import PropTypes from "prop-types";

function MapPanel({ points }) {
  const hasLocations = Array.isArray(points) && points.length > 0;

  return (
    <section className="sbdp-day-planner__map">
      <h4>Locaties</h4>
      {!hasLocations ? (
        <p>
          Locaties verschijnen hier zodra activiteiten een adres of coördinaten hebben. Voeg eerst een activiteit toe
          en koppel een locatie in het beheerpaneel.
        </p>
      ) : (
        <ul className="sbdp-day-planner__map-points">
          {points.map((point, index) => (
            <li key={point.id || index}>
              <strong>{point.title || point.label || "Activiteit"}</strong>
              {point.address ? <span>{point.address}</span> : null}
              {point.latitude && point.longitude ? (
                <span className="sbdp-day-planner__map-coordinates">
                  {Number(point.latitude).toFixed(4)}, {Number(point.longitude).toFixed(4)}
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      <p className="sbdp-day-planner__map-disclaimer">
        Kaartintegratie volgt in een latere fase; voorlopig gebruik je deze lijst als overzicht van ingeplande
        locaties.
      </p>
    </section>
  );
}

MapPanel.propTypes = {
  points: PropTypes.arrayOf(PropTypes.object).isRequired,
};

export default MapPanel;
