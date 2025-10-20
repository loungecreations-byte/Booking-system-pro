import React from "react";
import PropTypes from "prop-types";

function NotesPanel({ notes, onChange }) {
  return (
    <section className="sbdp-day-planner__notes">
      <h4>Notes</h4>
      <textarea value={notes} onChange={(event) => onChange(event.target.value)} rows={4} />
    </section>
  );
}

NotesPanel.propTypes = {
  notes: PropTypes.string.isRequired,
  onChange: PropTypes.func.isRequired,
};

export default NotesPanel;
