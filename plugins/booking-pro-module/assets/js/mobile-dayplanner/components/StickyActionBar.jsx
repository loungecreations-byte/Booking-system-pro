import React from "react";

import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";

export default function StickyActionBar() {
  const {
    state,
    selectors,
    actions: { savePlan, addToCart, submitPlan },
  } = usePlanner();
  const plannerActionState = selectors?.plannerActionState || {};
  const saveDisabled = state.plan.days.length === 0;

  return (
    <div className="sbdp-sticky-action-bar" role="group" aria-label="Planner acties">
      <button
        type="button"
        className="sbdp-button sbdp-button--secondary"
        onClick={() => savePlan({ silent: false }).catch(() => {})}
        disabled={saveDisabled}
      >
        Opslaan als concept
      </button>
      <button
        type="button"
        className="sbdp-button sbdp-button--primary"
        onClick={addToCart}
        disabled={!plannerActionState.primary_cta_enabled}
      >
        In winkelwagen
      </button>
      <button
        type="button"
        className="sbdp-button sbdp-button--ghost"
        onClick={() => submitPlan({ successMessage: "Offerte aangevraagd." })}
        disabled={!plannerActionState.secondary_quote_enabled}
      >
        Vraag offerte aan
      </button>
      {plannerActionState.blocking_reason_message ? (
        <p className="sbdp-sticky-action-bar__reason">{plannerActionState.blocking_reason_message}</p>
      ) : null}
    </div>
  );
}



