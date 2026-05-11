import React from "react";
import PropTypes from "prop-types";

const steps = [
  { id: "info", label: "1. Datum & deelnemers" },
  { id: "layout", label: "2. Activiteiten plannen" },
  { id: "review", label: "3. Controleren & versturen" },
];

export default function StepHeader({ step }) {
  return (
    <div className="sbdp-stepper">
      {steps.map((item) => {
        const status =
          item.id === step ? "current" : steps.findIndex((s) => s.id === item.id) <
            steps.findIndex((s) => s.id === step)
            ? "completed"
            : "upcoming";

        return (
          <div key={item.id} className={`sbdp-stepper__item sbdp-stepper__item--${status}`}>
            <span className="sbdp-stepper__bullet" />
            <span className="sbdp-stepper__label">{item.label}</span>
          </div>
        );
      })}
    </div>
  );
}

StepHeader.propTypes = {
  step: PropTypes.string.isRequired,
};

