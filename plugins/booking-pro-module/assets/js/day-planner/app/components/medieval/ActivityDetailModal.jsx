import React from "react";
import PropTypes from "prop-types";

import MedievalFrame from "./MedievalFrame.jsx";
import MedievalDivider from "./MedievalDivider.jsx";

export default function ActivityDetailModal({
  activity,
  isFavorite,
  onToggleFavorite,
  onReserve,
  onClose,
}) {
  if (!activity) {
    return null;
  }

  return (
    <div className="sbdp-medieval-modal" role="dialog" aria-modal="true" aria-label={activity.title}>
      <div className="sbdp-medieval-modal__card">
        <button type="button" className="sbdp-medieval-modal__close" onClick={onClose} aria-label="Sluiten">
          ×
        </button>
        <MedievalFrame src={activity.image} alt={activity.title} className="sbdp-medieval-modal__frame">
          <button
            type="button"
            className={`sbdp-favorite-toggle ${isFavorite ? "is-active" : ""}`.trim()}
            onClick={() => onToggleFavorite(activity)}
            aria-label={isFavorite ? "Verwijder uit favorieten" : "Voeg toe aan favorieten"}
          >
            {isFavorite ? "♥" : "♡"}
          </button>
        </MedievalFrame>
        <h2 className="sbdp-medieval-title">{activity.title}</h2>
        <p className="sbdp-medieval-copy">{activity.description}</p>
        <MedievalDivider label="Locatie" />
        <p className="sbdp-medieval-copy">
          <strong>{activity.location}</strong>
        </p>
        <div className="sbdp-medieval-modal__actions">
          <button type="button" className="sbdp-medieval-btn sbdp-medieval-btn--ghost" onClick={onClose}>
            Terug
          </button>
          <button type="button" className="sbdp-medieval-btn" onClick={() => onReserve(activity)}>
            Reserveer
          </button>
        </div>
      </div>
    </div>
  );
}

ActivityDetailModal.propTypes = {
  activity: PropTypes.shape({
    id: PropTypes.number,
    title: PropTypes.string,
    image: PropTypes.string,
    description: PropTypes.string,
    location: PropTypes.string,
  }),
  isFavorite: PropTypes.bool,
  onToggleFavorite: PropTypes.func.isRequired,
  onReserve: PropTypes.func.isRequired,
  onClose: PropTypes.func.isRequired,
};

ActivityDetailModal.defaultProps = {
  activity: null,
  isFavorite: false,
};
