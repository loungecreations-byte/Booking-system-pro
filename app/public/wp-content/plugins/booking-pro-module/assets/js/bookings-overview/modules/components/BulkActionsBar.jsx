import React from "react";

function BulkActionsBar({ selectedCount, bulkActions, onRun, disabled, activeAction, i18n }) {
  if (!Array.isArray(bulkActions) || bulkActions.length === 0) {
    return null;
  }

  const labelTemplate = i18n.selectedCount || "%d geselecteerd";
  const selectedLabel =
    selectedCount > 0 ? labelTemplate.replace("%d", selectedCount) : i18n.bulkActions || "Bulkacties";

  return (
    <div className="sbdp-bookings-overview-actions">
      <strong>{selectedLabel}</strong>
      {bulkActions.map((action) => (
        <button
          type="button"
          key={action.action}
          className="button"
          onClick={() => onRun(action.action)}
          disabled={disabled || activeAction === action.action}
        >
          {activeAction === action.action ? i18n.loading || "Bezig…" : action.label}
        </button>
      ))}
    </div>
  );
}

export default BulkActionsBar;
