import React from "react";
import PropTypes from "prop-types";

import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";

export default function DateParticipantForm({ visible }) {
  const {
    state,
    actions: { setFormField, setParticipantsIngress, startPlanning },
  } = usePlanner();

  const handleSubmit = (event) => {
    event.preventDefault();
    startPlanning();
  };

  const wrapperClass = visible
    ? "sbdp-mobile-panel is-visible"
    : "sbdp-mobile-panel is-hidden";

  return (
    <section className={wrapperClass} aria-hidden={!visible}>
      <header className="sbdp-mobile-panel__header">
        <h2>Plan je dag</h2>
        <p className="sbdp-mobile-panel__intro">
          Kies een datum en aantal deelnemers. Je kunt dit later altijd nog wijzigen.
        </p>
      </header>

      <form className="sbdp-mobile-form" onSubmit={handleSubmit}>
        <label className="sbdp-mobile-form__row">
          <span>Datum</span>
          <input
            type="date"
            value={state.form.date}
            onChange={(event) => setFormField("date", event.target.value)}
            required
          />
        </label>

        <label className="sbdp-mobile-form__row">
          <span>Deelnemers</span>
          <input
            type="number"
            min="1"
            inputMode="numeric"
            value={state.form.participants}
            onChange={(event) => setParticipantsIngress(event.target.value, { mode: "typing" })}
            required
          />
        </label>

        <div className="sbdp-mobile-form__actions">
          <button type="submit" className="sbdp-button sbdp-button--primary">
            {state.plan.days.length > 0 ? "Update planning" : "Start met plannen"}
          </button>
        </div>
      </form>
    </section>
  );
}

DateParticipantForm.propTypes = {
  visible: PropTypes.bool,
};

DateParticipantForm.defaultProps = {
  visible: true,
};
