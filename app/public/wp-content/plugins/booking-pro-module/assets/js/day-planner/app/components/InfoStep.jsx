import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { usePlanner } from "../../store/PlannerProvider.jsx";
import {
  getFeaturedPlannerPresets,
  getPlannerPresetById,
  PLANNER_PRESETS,
} from "../utils/planner-presets.js";
import { emitPlannerEvent } from "../utils/telemetry.js";
import { getLocalDateIso } from "../utils/time.js";

const FALLBACK_PARTICIPANTS = 10;
const FEATURED_PRESETS = getFeaturedPlannerPresets();
const MIN_PARTICIPANTS = 1;

const AUDIENCE_OPTIONS = [
  { value: "vrienden", label: "Vrienden" },
  { value: "romantisch", label: "Romantisch" },
  { value: "familie", label: "Familie" },
  { value: "solo", label: "Solo" },
  { value: "bedrijf", label: "Zakelijk" },
];

const DURATION_OPTIONS = [
  { value: "ochtend", label: "Ochtend" },
  { value: "middag", label: "Middag" },
  { value: "avond", label: "Avond" },
  { value: "hele-dag", label: "Hele dag" },
];

function findBestPreset(audience, duration) {
  const exact = PLANNER_PRESETS.find((p) => p.audience === audience && p.duration === duration);
  if (exact) return exact.id;
  const byAudience = PLANNER_PRESETS.find((p) => p.audience === audience);
  if (byAudience) return byAudience.id;
  return PLANNER_PRESETS[0]?.id || "first-timer";
}

export default function InfoStep() {
  const {
    state: { form, config, plan },
    actions: {
      setFormField,
      setParticipantsIngress,
      setFilters,
      setWidgetPreferences,
      startPlanning,
      clearPlan,
      generatePresetPlan,
    },
  } = usePlanner();

  const [selectedAudience, setSelectedAudience] = useState("vrienden");
  const [selectedDuration, setSelectedDuration] = useState("hele-dag");
  const [selectedPreset, setSelectedPreset] = useState(() => findBestPreset("vrienden", "hele-dag"));
  const [participantInput, setParticipantInput] = useState(String(FALLBACK_PARTICIPANTS));
  const [isParticipantEditing, setIsParticipantEditing] = useState(false);
  const participantInteractionRef = useRef(false);
  const pendingThemeRef = useRef(null);
  const dateInputRef = useRef(null);

  const today = useMemo(() => getLocalDateIso(), []);

  const firstPlanDate = plan?.days?.[0]?.date;
  const initialDate = form.date || firstPlanDate || today;
  const initialParticipants =
    form.participants ||
    config?.default_participants ||
    FALLBACK_PARTICIPANTS;

  useEffect(() => {
    if (!form.date && firstPlanDate) {
      setFormField("date", initialDate);
    }
  }, [form.date, firstPlanDate, initialDate, setFormField]);

  useEffect(() => {
    if (!form.participants && initialParticipants && !participantInteractionRef.current) {
      setFormField("participants", String(initialParticipants));
    }
  }, [form.participants, initialParticipants, setFormField]);

  const participantValue = Math.max(
    MIN_PARTICIPANTS,
    Number.parseInt(form.participants || initialParticipants, 10) || MIN_PARTICIPANTS
  );

  useEffect(() => {
    if (!isParticipantEditing) {
      setParticipantInput(String(participantValue));
    }
  }, [participantValue, isParticipantEditing]);

  const applyPreset = ({ includeScheduling = false, participantsOverride = null } = {}) => {
    const selectedPresetConfig = getPlannerPresetById(selectedPreset);
    if (!selectedPresetConfig) {
      return;
    }

    const presetVibe = typeof selectedPresetConfig.vibe === "string" ? selectedPresetConfig.vibe : "";
    const vibeTokens = presetVibe.toLowerCase();

    const nextPreferences = {
      duration: selectedPresetConfig.duration || null,
      audience: selectedPresetConfig.audience || null,
      vibe: selectedPresetConfig.vibe || null,
    };

    if (includeScheduling) {
      nextPreferences.count = participantsOverride ?? participantValue;
      nextPreferences.visitDate = form.date || initialDate;
    }

    setWidgetPreferences(nextPreferences);
    setFilters({
      environment: vibeTokens.includes("indoor")
        ? "indoor"
        : vibeTokens.includes("buitenlucht") || vibeTokens.includes("outdoor") || vibeTokens.includes("actief")
        ? "outdoor"
        : "both",
      search: "",
    });
    setFormField("vibe", selectedPresetConfig.vibe || "");
    setFormField("plannerPreset", selectedPresetConfig.id);
  };

  useEffect(() => {
    applyPreset();
  }, [selectedPreset]);

  useEffect(() => {
    if (pendingThemeRef.current && plan?.days?.length > 0) {
      const theme = pendingThemeRef.current;
      pendingThemeRef.current = null;
      generatePresetPlan(theme);
    }
  }, [plan?.days?.length, generatePresetPlan]);

  useEffect(() => {
    emitPlannerEvent("sbdp:planner/start-intent", {
      status: "auto",
      mode: "hidden-initializer",
      date: form.date || initialDate,
      participants: participantValue,
    });
  }, [form.date, initialDate, participantValue]);

  const handleAudienceChange = (value) => {
    setSelectedAudience(value);
    setSelectedPreset(findBestPreset(value, selectedDuration));
  };

  const handleDurationChange = (value) => {
    setSelectedDuration(value);
    setSelectedPreset(findBestPreset(selectedAudience, value));
  };

  const resolveCommittedParticipantValue = () => {
    const parsedInput = Number.parseInt(participantInput, 10);
    if (Number.isFinite(parsedInput) && parsedInput >= MIN_PARTICIPANTS) {
      return parsedInput;
    }
    return participantValue;
  };

  const applyParticipantIngress = useCallback(
    (rawValue, { mode = "commit", editing = false } = {}) => {
      participantInteractionRef.current = true;
      setIsParticipantEditing(editing);
      setParticipantInput(rawValue);
      return setParticipantsIngress(rawValue, { mode });
    },
    [setParticipantsIngress]
  );

  const handleParticipantDelta = useCallback(
    (delta) => {
      const parsedDelta = Number.parseInt(String(delta), 10);
      if (!Number.isFinite(parsedDelta) || parsedDelta === 0) {
        return;
      }
      const nextValue = Math.max(MIN_PARTICIPANTS, resolveCommittedParticipantValue() + parsedDelta);
      applyParticipantIngress(String(nextValue), { mode: "commit", editing: false });
    },
    [applyParticipantIngress, participantInput, participantValue]
  );

  const handleParticipantInputChange = (event) => {
    const rawValue = event.target.value;
    if (!/^\d*$/.test(rawValue)) {
      return;
    }

    applyParticipantIngress(rawValue, { mode: "typing", editing: true });
  };

  const commitParticipantInput = () => {
    const nextValue = Math.max(
      MIN_PARTICIPANTS,
      resolveCommittedParticipantValue() || MIN_PARTICIPANTS
    );
    applyParticipantIngress(String(nextValue), { mode: "commit", editing: false });
  };

  const handleStartPlanning = () => {
    const committedDate = form.date || initialDate;
    const committedParticipants = Math.max(
      MIN_PARTICIPANTS,
      Number.parseInt(participantInput, 10) || participantValue || MIN_PARTICIPANTS
    );
    setIsParticipantEditing(false);
    setParticipantInput(String(committedParticipants));
    if (committedDate) {
      setFormField("date", committedDate);
    }
    setFormField("participants", String(committedParticipants));

    emitPlannerEvent("sbdp:planner/start-intent", {
      status: "manual",
      mode: "compact-controls",
      date: committedDate,
      participants: committedParticipants,
      preset: selectedPreset,
    });

    const presetConfig = getPlannerPresetById(selectedPreset);
    const vibe = (presetConfig?.vibe || "").toLowerCase();
    let theme = "mix";
    if (vibe.includes("bourgondisch") || vibe.includes("food")) theme = "bourgondisch";
    else if (vibe.includes("teambuilding")) theme = "teambuilding";
    else if (vibe.includes("actief")) theme = "actief";
    else if (vibe.includes("cultuur") || vibe.includes("museum") || vibe.includes("klassiek") || vibe.includes("histor")) theme = "mystiek";

    pendingThemeRef.current = theme;
    if (plan?.items?.length > 0) {
      clearPlan();
    }
    startPlanning();
  };

  const displayDate = useMemo(() => {
    const d = form.date || initialDate;
    if (!d) return "Selecteer";
    const p = d.split("-");
    return p.length === 3 ? `${p[2]} - ${p[1]} - ${p[0]}` : d;
  }, [form.date, initialDate]);

  const audienceLabel = AUDIENCE_OPTIONS.find((o) => o.value === selectedAudience)?.label || "Kies";
  const durationLabel = DURATION_OPTIONS.find((o) => o.value === selectedDuration)?.label || "Kies";

  return (
    <section className="sbdp-day-planner__hero sbdp-hero--compact sbdp-hero--compact-active sbdp-hero--compact-subtle">
      <style>{`
        .sbdp-pb {
          display: flex;
          flex-direction: row;
          align-items: stretch;
          width: 100%;
          box-sizing: border-box;
          padding: 6px;
          gap: 2px;
          min-height: 80px;
          border-radius: inherit;
        }
        .sbdp-pb-field {
          flex: 1;
          display: flex;
          flex-direction: row;
          align-items: center;
          gap: 14px;
          padding: 0 22px;
          border-radius: 40px;
          position: relative;
          cursor: pointer;
          transition: background 0.18s;
          min-width: 0;
          overflow: visible;
          text-decoration: none;
        }
        .sbdp-pb-field:hover {
          background: rgba(255,255,255,0.08);
        }
        .sbdp-pb-field:hover .sbdp-pb-badge {
          background: #e4b97f;
          color: #000;
        }
        .sbdp-pb-badge {
          flex-shrink: 0;
          width: 26px;
          height: 26px;
          border-radius: 50%;
          background: rgba(255,255,255,0.12);
          color: rgba(255,255,255,0.75);
          font-size: 11px;
          font-weight: 700;
          display: flex;
          align-items: center;
          justify-content: center;
          pointer-events: none;
          position: relative;
          z-index: 2;
        }
        .sbdp-pb-content {
          display: flex;
          flex-direction: column;
          gap: 4px;
          min-width: 0;
          flex: 1;
          pointer-events: none;
          position: relative;
          z-index: 2;
        }
        .sbdp-pb-label {
          font-size: 10px;
          font-weight: 500;
          text-transform: uppercase;
          letter-spacing: 0.08em;
          color: rgba(255,255,255,0.45);
          white-space: nowrap;
          line-height: 1;
          margin: 0;
        }
        .sbdp-pb-value {
          font-size: 16px;
          font-weight: 600;
          color: #fff;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          line-height: 1.2;
        }
        .sbdp-pb-chevron {
          color: rgba(228,185,127,0.85);
          flex-shrink: 0;
          pointer-events: none;
          position: relative;
          z-index: 2;
          display: flex;
          align-items: center;
        }
        /* Invisible overlay covering the entire field — triggers native picker */
        .sbdp-pb-overlay {
          position: absolute;
          inset: 0;
          width: 100%;
          height: 100%;
          opacity: 0;
          cursor: pointer;
          z-index: 1;
          border: none;
          outline: none;
          background: transparent;
          -webkit-appearance: none;
          appearance: none;
          font-size: 16px;
          padding: 0;
          margin: 0;
        }
        .sbdp-pb-overlay::-webkit-calendar-picker-indicator {
          position: absolute;
          inset: 0;
          width: 100%;
          height: 100%;
          opacity: 0;
          cursor: pointer;
        }
        /* Divider between fields */
        .sbdp-pb-divider {
          width: 1px;
          flex-shrink: 0;
          background: rgba(255,255,255,0.1);
          align-self: center;
          height: 40px;
          border-radius: 1px;
        }
        /* Participants stepper — z-index above overlay */
        .sbdp-pb-stepper {
          display: flex;
          align-items: center;
          gap: 12px;
          position: relative;
          z-index: 3;
          pointer-events: all;
        }
        .sbdp-pb-stepper-btn {
          width: 30px;
          height: 30px;
          min-width: 30px;
          border-radius: 50%;
          background: rgba(255,255,255,0.1);
          color: #fff;
          border: none;
          font-size: 20px;
          font-weight: 300;
          line-height: 1;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          padding: 0;
          margin: 0;
          transition: background 0.15s;
        }
        .sbdp-pb-stepper-btn:not(:disabled):hover {
          background: rgba(255,255,255,0.25);
        }
        .sbdp-pb-stepper-btn:disabled {
          opacity: 0.3;
          cursor: default;
        }
        .sbdp-pb-stepper-val {
          width: 32px;
          text-align: center;
          font-size: 16px;
          font-weight: 600;
          color: #fff;
          background: transparent;
          border: none;
          padding: 0;
          outline: none;
          -moz-appearance: textfield;
        }
        .sbdp-pb-stepper-val::-webkit-inner-spin-button,
        .sbdp-pb-stepper-val::-webkit-outer-spin-button {
          -webkit-appearance: none;
        }
        /* CTA button */
        .sbdp-pb-cta {
          flex-shrink: 0;
          align-self: center;
          margin: 6px;
          height: 64px;
          padding: 0 36px;
          border-radius: 999px;
          background: #e4b97f;
          color: #000;
          border: none;
          font-size: 16px;
          font-weight: 700;
          white-space: nowrap;
          cursor: pointer;
          min-width: 190px;
          transition: background 0.18s, transform 0.1s;
        }
        .sbdp-pb-cta:hover {
          background: #f0c98e;
          transform: scale(1.02);
        }
        .sbdp-pb-cta:active {
          transform: scale(0.98);
        }
      `}</style>

      <div className="sbdp-hero-compact sbdp-hero-compact--controls-only sbdp-hero-compact--one-line">
        <div className="sbdp-pb" role="group" aria-label="Planner instellingen">

          {/* Stap 1 — Datum */}
          <label
            className="sbdp-pb-field"
            onClick={(e) => {
              e.preventDefault();
              dateInputRef.current?.showPicker?.();
            }}
          >
            <span className="sbdp-pb-badge">1</span>
            <div className="sbdp-pb-content">
              <span className="sbdp-pb-label">Kies datum</span>
              <span className="sbdp-pb-value">{displayDate}</span>
            </div>
            <span className="sbdp-pb-chevron">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                <line x1="16" y1="2" x2="16" y2="6" />
                <line x1="8" y1="2" x2="8" y2="6" />
                <line x1="3" y1="10" x2="21" y2="10" />
              </svg>
            </span>
            <input
              ref={dateInputRef}
              type="date"
              className="sbdp-pb-overlay"
              min={today}
              value={form.date || initialDate}
              onChange={(event) => setFormField("date", event.target.value)}
            />
          </label>

          <div className="sbdp-pb-divider" />

          {/* Stap 2 — Personen */}
          <div className="sbdp-pb-field">
            <span className="sbdp-pb-badge">2</span>
            <div className="sbdp-pb-content">
              <span className="sbdp-pb-label">Aantal personen</span>
              <div className="sbdp-pb-stepper">
                <button
                  type="button"
                  className="sbdp-pb-stepper-btn"
                  aria-label="Verlaag deelnemers"
                  disabled={participantValue <= MIN_PARTICIPANTS}
                  onClick={() => handleParticipantDelta(-1)}
                >
                  −
                </button>
                <input
                  type="number"
                  className="sbdp-pb-stepper-val"
                  min="1"
                  inputMode="numeric"
                  value={participantInput}
                  onFocus={() => setIsParticipantEditing(true)}
                  onBlur={commitParticipantInput}
                  onChange={handleParticipantInputChange}
                  onKeyDown={(event) => {
                    if (event.key === "Enter") {
                      event.preventDefault();
                      commitParticipantInput();
                    }
                  }}
                  aria-label="Aantal deelnemers"
                />
                <button
                  type="button"
                  className="sbdp-pb-stepper-btn"
                  aria-label="Verhoog deelnemers"
                  onClick={() => handleParticipantDelta(1)}
                >
                  +
                </button>
              </div>
            </div>
          </div>

          <div className="sbdp-pb-divider" />

          {/* Stap 3 — Voorkeur */}
          <label className="sbdp-pb-field" htmlFor="sbdp-pb-audience">
            <span className="sbdp-pb-badge">3</span>
            <div className="sbdp-pb-content">
              <span className="sbdp-pb-label">Voorkeur</span>
              <span className="sbdp-pb-value">{audienceLabel}</span>
            </div>
            <span className="sbdp-pb-chevron">
              <svg width="12" height="8" viewBox="0 0 12 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M1 1.5L6 6.5L11 1.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </span>
            <select
              id="sbdp-pb-audience"
              className="sbdp-pb-overlay"
              value={selectedAudience}
              onChange={(e) => handleAudienceChange(e.target.value)}
              aria-label="Kies voorkeur"
            >
              {AUDIENCE_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>
          </label>

          <div className="sbdp-pb-divider" />

          {/* Stap 4 — Dagdeel */}
          <label className="sbdp-pb-field" htmlFor="sbdp-pb-duration">
            <span className="sbdp-pb-badge">4</span>
            <div className="sbdp-pb-content">
              <span className="sbdp-pb-label">Dagdeel</span>
              <span className="sbdp-pb-value">{durationLabel}</span>
            </div>
            <span className="sbdp-pb-chevron">
              <svg width="12" height="8" viewBox="0 0 12 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M1 1.5L6 6.5L11 1.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </span>
            <select
              id="sbdp-pb-duration"
              className="sbdp-pb-overlay"
              value={selectedDuration}
              onChange={(e) => handleDurationChange(e.target.value)}
              aria-label="Kies dagdeel"
            >
              {DURATION_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>
          </label>

          {/* Stap 5 — CTA */}
          <button
            type="button"
            className="sbdp-pb-cta"
            onClick={handleStartPlanning}
          >
            Start plannen
          </button>

        </div>
      </div>
    </section>
  );
}
