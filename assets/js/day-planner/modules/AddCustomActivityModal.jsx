import React from "react";
import PropTypes from "prop-types";

function AddCustomActivityModal({ isOpen, onClose }) {
  if (!isOpen) {
    return null;
  }

  return (
    <div className="sbdp-modal" role="dialog" aria-modal="true" aria-labelledby="sbdp-custom-activity-title">
      <div className="sbdp-modal__dialog">
        <header className="sbdp-modal__header">
          <h3 id="sbdp-custom-activity-title">Eigen activiteit toevoegen</h3>
          <button type="button" onClick={onClose} aria-label="Dialoog sluiten">
            &times;
          </button>
        </header>
        <div className="sbdp-modal__body">
          <p>
            Hier kun je straks handmatig een activiteit aanmaken (bijvoorbeeld een partnerafspraak of intern moment).
            Deze functionaliteit wordt in een volgende iteratie verder uitgewerkt.
          </p>
        </div>
        <footer className="sbdp-modal__footer">
          <button type="button" className="button" onClick={onClose}>
            Sluiten
          </button>
        </footer>
      </div>
    </div>
  );
}

AddCustomActivityModal.propTypes = {
  isOpen: PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
};

export default AddCustomActivityModal;
