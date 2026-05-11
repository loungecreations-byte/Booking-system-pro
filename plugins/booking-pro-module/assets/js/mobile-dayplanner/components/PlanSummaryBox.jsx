import React, { useMemo } from "react";

import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";

export default function PlanSummaryBox() {
  const { state, actions, selectors } = usePlanner();
  const plannerActionState = selectors?.plannerActionState || {};
  const currency = state.summary?.currency || "EUR";

  const totals = useMemo(() => {
    const subtotal = state.summary?.subtotal || 0;
    const adjustments = state.summary?.adjustments || [];
    const participants = Number.isFinite(selectors?.canonicalParticipants)
      ? selectors.canonicalParticipants
      : 0;

    return {
      subtotal,
      adjustments,
      participants,
      formattedSubtotal: formatCurrency(subtotal, currency),
      formattedPerPerson:
        participants > 0 ? formatCurrency(subtotal / participants, currency) : null,
    };
  }, [state.summary, state.plan?.participants, state.form?.participants, currency]);

  if (!state.plan.days.length) {
    return null;
  }

  return (
    <section className="sbdp-mobile-panel">
      <header className="sbdp-mobile-panel__header">
        <h3>Samenvatting</h3>
      </header>

      <div className="sbdp-summary-box">
        <div className="sbdp-summary-box__row">
          <span>Totaal (indicatief)</span>
          <strong>{totals.formattedSubtotal}</strong>
        </div>

        {totals.participants > 0 && totals.formattedPerPerson ? (
          <div className="sbdp-summary-box__row sbdp-summary-box__row--muted">
            <span>Per persoon</span>
            <span>{totals.formattedPerPerson}</span>
          </div>
        ) : null}

        {totals.adjustments.length ? (
          <ul className="sbdp-summary-box__adjustments">
            {totals.adjustments.map((adjustment, index) => (
              <li key={index}>
                <span>{adjustment.label || "Aanpassing"}</span>
                <span>{formatCurrency(adjustment.amount || 0, currency)}</span>
              </li>
            ))}
          </ul>
        ) : null}

        <button
          type="button"
          className="sbdp-button sbdp-button--link"
          onClick={() => actions.savePlan({ silent: false }).catch(() => {})}
        >
          Opslaan
        </button>
        {plannerActionState.blocking_reason_message ? (
          <p className="sbdp-summary-box__reason">{plannerActionState.blocking_reason_message}</p>
        ) : null}
      </div>
    </section>
  );
}

function formatCurrency(value, currency) {
  try {
    return new Intl.NumberFormat("nl-NL", {
      style: "currency",
      currency,
    }).format(value || 0);
  } catch (error) {
    return `${(value || 0).toFixed(2)} ${currency}`;
  }
}



