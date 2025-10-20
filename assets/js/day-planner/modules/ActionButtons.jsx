import React from "react";
import PropTypes from "prop-types";

function ActionButtons({ onBookNow, onRequestQuote, onShare, onExportPdf, onExportIcs }) {
  return (
    <div className="sbdp-day-planner__actions">
      <button type="button" onClick={onBookNow}>
        Boek &amp; Betaal
      </button>
      <button type="button" onClick={onRequestQuote}>
        Doe aanvraag
      </button>
      <button type="button" onClick={onShare}>
        Deel programma
      </button>
      <button type="button" onClick={onExportPdf}>
        Exporteer PDF
      </button>
      <button type="button" onClick={onExportIcs}>
        Voeg toe aan agenda
      </button>
    </div>
  );
}

ActionButtons.propTypes = {
  onBookNow: PropTypes.func.isRequired,
  onRequestQuote: PropTypes.func.isRequired,
  onShare: PropTypes.func.isRequired,
  onExportPdf: PropTypes.func.isRequired,
  onExportIcs: PropTypes.func.isRequired,
};

export default ActionButtons;
