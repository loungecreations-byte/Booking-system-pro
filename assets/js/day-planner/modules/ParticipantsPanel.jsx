import React, { useMemo, useState } from "react";
import PropTypes from "prop-types";

const ROLE_OPTIONS = [
  { value: "guest", label: "Gast" },
  { value: "organiser", label: "Organisator" },
  { value: "guide", label: "Begeleider" },
];

function ParticipantsPanel({ participants, onAdd, onUpdate, onRemove }) {
  const [draft, setDraft] = useState({ name: "", email: "", role: "guest" });

  const hasParticipants = participants && participants.length > 0;
  const roles = useMemo(() => ROLE_OPTIONS, []);

  const handleDraftChange = (event) => {
    const { name, value } = event.target;
    setDraft((prev) => ({
      ...prev,
      [name]: value,
    }));
  };

  const submitDraft = (event) => {
    event.preventDefault();
    if (!onAdd) {
      return;
    }

    onAdd(draft);
    setDraft({ name: "", email: "", role: "guest" });
  };

  return (
    <section className="sbdp-day-planner__participants">
      <h4>Deelnemers</h4>
      {hasParticipants ? (
        <ul className="sbdp-participants">
          {participants.map((participant, index) => (
            <li key={participant.email || `${participant.name}-${index}`} className="sbdp-participant">
              <div className="sbdp-participant__fields">
                <label>
                  <span>Naam</span>
                  <input
                    type="text"
                    value={participant.name || ""}
                    onChange={(event) =>
                      onUpdate(index, { name: event.target.value })
                    }
                    placeholder="Naam"
                  />
                </label>
                <label>
                  <span>E-mail</span>
                  <input
                    type="email"
                    value={participant.email || ""}
                    onChange={(event) =>
                      onUpdate(index, { email: event.target.value })
                    }
                    placeholder="voorbeeld@bedrijf.nl"
                  />
                </label>
                <label>
                  <span>Rol</span>
                  <select
                    value={participant.role || "guest"}
                    onChange={(event) =>
                      onUpdate(index, { role: event.target.value })
                    }
                  >
                    {roles.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
              <button
                type="button"
                className="button button-link-delete"
                onClick={() => onRemove(index)}
              >
                Verwijderen
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p>Voeg deelnemers toe om contactgegevens te bewaren.</p>
      )}

      <form className="sbdp-participants__form" onSubmit={submitDraft}>
        <h5>Deelnemer toevoegen</h5>
        <div className="sbdp-participant__fields">
          <label>
            <span>Naam</span>
            <input
              type="text"
              name="name"
              value={draft.name}
              onChange={handleDraftChange}
              placeholder="Naam"
            />
          </label>
          <label>
            <span>E-mail</span>
            <input
              type="email"
              name="email"
              value={draft.email}
              onChange={handleDraftChange}
              placeholder="voorbeeld@bedrijf.nl"
            />
          </label>
          <label>
            <span>Rol</span>
            <select name="role" value={draft.role} onChange={handleDraftChange}>
              {roles.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        </div>
        <button type="submit" className="button button-secondary">
          Opslaan
        </button>
      </form>
    </section>
  );
}

ParticipantsPanel.propTypes = {
  participants: PropTypes.arrayOf(PropTypes.object).isRequired,
  onAdd: PropTypes.func.isRequired,
  onUpdate: PropTypes.func.isRequired,
  onRemove: PropTypes.func.isRequired,
};

export default ParticipantsPanel;
