import React from "react";

import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";
import { buildPlannerCtaModel } from "../../day-planner/app/utils/planner-cta.js";

export default function StickyActionBar() {
  const {
    state,
    selectors,
    actions: { savePlan, addToCart, submitPlan },
  } = usePlanner();
  const plannerActionState = selectors?.plannerActionState || {};
  const saveDisabled = state.plan.days.length === 0;
  const plannerCtaModel = buildPlannerCtaModel({
    plannerActionState,
    formattedTotal: "",
  });
  const handlePrimaryAction = () => {
    if (plannerCtaModel.primary.key === "checkout") {
      return addToCart();
    }
    if (plannerCtaModel.primary.key === "quote") {
      return submitPlan({ successMessage: "Offerte aangevraagd." });
    }
    return savePlan({ silent: false }).catch(() => {});
  };
  const handleSecondaryAction = () => {
    if (plannerCtaModel.secondary?.key === "quote") {
      return submitPlan({ successMessage: "Offerte aangevraagd." });
    }

    document
      .querySelector(".sbdp-day-planner__primary-status, .sbdp-planner-checkout")
      ?.scrollIntoView({ behavior: "smooth", block: "center" });
    return undefined;
  };

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
        className={`sbdp-button sbdp-button--${plannerCtaModel.primary.variant}`}
        onClick={handlePrimaryAction}
        disabled={!plannerCtaModel.primary.enabled}
        aria-label={plannerCtaModel.primary.ariaLabel}
        data-planner-action={plannerCtaModel.primary.key}
      >
        {plannerCtaModel.primary.label || "In winkelwagen"}
      </button>
      {plannerCtaModel.secondary ? (
        <button
          type="button"
          className={`sbdp-button sbdp-button--${plannerCtaModel.secondary.variant}`}
          onClick={handleSecondaryAction}
          disabled={!plannerCtaModel.secondary.enabled}
          aria-label={plannerCtaModel.secondary.ariaLabel}
          data-planner-action={plannerCtaModel.secondary.key}
        >
          {plannerCtaModel.secondary.label}
        </button>
      ) : null}
      {plannerActionState.blocking_reason_message ? (
        <p className="sbdp-sticky-action-bar__reason">{plannerActionState.blocking_reason_message}</p>
      ) : null}
    </div>
  );
}



