import React from "react";
import PropTypes from "prop-types";

function AIDialog({ suggestions, onClose }) {
  if (!suggestions) {
    return null;
  }

  const { summary, activities } = suggestions;

  return (
    <div className="sbdp-modal" role="dialog" aria-modal="true" aria-labelledby="sbdp-ai-dialog-title">
      <div className="sbdp-modal__dialog">
        <header className="sbdp-modal__header">
          <h3 id="sbdp-ai-dialog-title">AI-voorstellen</h3>
          <button type="button" onClick={onClose} aria-label="Dialoog sluiten">
            &times;
          </button>
        </header>
        <div className="sbdp-modal__body">
          {summary ? <p>{summary}</p> : null}
          {Array.isArray(activities) && activities.length > 0 ? (
            <ul>
              {activities.map((item, index) => (
                <li key={item.id || index}>{item.title || item.label || "Activiteit"}</li>
              ))}
            </ul>
          ) : null}
          <details>
            <summary>Technische details</summary>
            <pre>{JSON.stringify(suggestions, null, 2)}</pre>
          </details>
        </div>
      </div>
    </div>
  );
}

AIDialog.propTypes = {
  suggestions: PropTypes.object,
  onClose: PropTypes.func.isRequired,
};

AIDialog.defaultProps = {
  suggestions: null,
};

export default AIDialog;
