import React from "react";

import { usePlanner } from "../../store/PlannerProvider.jsx";
import { formatDateLabel } from "../utils/time.js";

export default function ReviewStep() {
  const {
    state,
    actions: { backToLayout, submitPlan },
  } = usePlanner();

  const currency = state.summary.currency || "EUR";
  const summaryItems = Array.isArray(state.summary.items) ? state.summary.items : [];
  const grandTotal = Number.isFinite(state.summary.grandTotal)
    ? state.summary.grandTotal
    : state.summary.subtotal || 0;

  return (
    <section className="sbdp-review">
      <header>
        <h2>Controleer je dagplanning</h2>
        <p>Alles in orde? Verstuur de planning of ga terug om wijzigingen aan te brengen.</p>
      </header>
      <div className="sbdp-review__content">
        <div className="sbdp-review__summary">
          <h3>Samenvatting</h3>
          <p>
            <strong>{state.plan.items.length}</strong> activiteit(en) voor{" "}
            <strong>{state.plan.participants}</strong> deelnemer
            {state.plan.participants === 1 ? "" : "s"}.
          </p>
          <p>
            Totaal:{" "}
            <strong>
              {new Intl.NumberFormat("nl-NL", {
                style: "currency",
                currency,
              }).format(grandTotal || 0)}
            </strong>
          </p>
        </div>

        <div className="sbdp-review__list">
          <h3>Activiteiten</h3>
          <ul>
            {state.plan.items.map((item, index) => {
              const day = state.plan.days[item.dayIndex] || {};
              const summaryItem = summaryItems[index];
              const cost = summaryItem?.line_subtotal ?? item.totalCost ?? 0;
              return (
                <li key={item.id}>
                  <h4>{item.title}</h4>
                  <p>
                    {formatDateLabel(day.date || "", state.config?.locale || "nl-NL")} -{" "}
                    {item.startTime} tot {item.endTime} ({item.participants} deelnemer
                    {item.participants === 1 ? "" : "s"})
                  </p>
                  <p>
                    Kostprijs:{" "}
                    {new Intl.NumberFormat("nl-NL", {
                      style: "currency",
                      currency,
                    }).format(cost)}
                  </p>
                </li>
              );
            })}
          </ul>
        </div>
      </div>
      <footer className="sbdp-review__actions">
        <button
          type="button"
          className="ui-btn ui-btn--secondary"
          onClick={backToLayout}
        >
          Terug naar activiteiten
        </button>
        <button
          type="button"
          className="ui-btn ui-btn--primary ui-btn--planner"
          onClick={submitPlan}
        >
          Planning versturen
        </button>
      </footer>
    </section>
  );
}
