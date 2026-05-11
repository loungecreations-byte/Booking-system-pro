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

  return (
    <section className="sbdp-day-planner__hero sbdp-hero--compact sbdp-hero--compact-active sbdp-hero--compact-subtle">
      <div className="sbdp-hero-compact sbdp-hero-compact--controls-only">
        <div className="sbdp-hero-compact__fields" role="group" aria-label="Planner instellingen">
          <label className="sbdp-field">
            <span className="sbdp-field__label">Kies datum</span>
            <input
              type="date"
              min={today}
              value={form.date || initialDate}
              onChange={(event) => setFormField("date", event.target.value)}
            />
          </label>
          <div className="sbdp-field sbdp-field-participants sbdp-participants-wide">
            <label className="sbdp-field__label" htmlFor="sbdp-planner-participants">
              Aantal deelnemers
            </label>
            <div
              className="sbdp-participants-stepper"
            >
              <button
                type="button"
                className="sbdp-participants-stepper__btn ui-btn ui-btn--secondary ui-btn--icon"
                aria-label="Verlaag deelnemers"
                disabled={participantValue <= MIN_PARTICIPANTS}
                onClick={() => handleParticipantDelta(-1)}
              >
                -1
              </button>
              <input
                id="sbdp-planner-participants"
                type="number"
                className="sbdp-participants-stepper__value"
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
                className="sbdp-participants-stepper__btn ui-btn ui-btn--secondary ui-btn--icon"
                aria-label="Verhoog deelnemers"
                onClick={() => handleParticipantDelta(1)}
              >
                +1
              </button>
            </div>
          </div>
          <label className="sbdp-field">
            <span className="sbdp-field__label">Met wie</span>
            <select
              className="sbdp-select"
              value={selectedAudience}
              onChange={(e) => handleAudienceChange(e.target.value)}
              aria-label="Kies gezelschap"
            >
              {AUDIENCE_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>
          </label>
          <label className="sbdp-field">
            <span className="sbdp-field__label">Dagdeel</span>
            <select
              className="sbdp-select"
              value={selectedDuration}
              onChange={(e) => handleDurationChange(e.target.value)}
              aria-label="Kies dagdeel"
            >
              {DURATION_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>
          </label>
          <div className="sbdp-field sbdp-field--action">
            <span className="sbdp-field__label">Planner</span>
            <button
              type="button"
              className="ui-btn ui-btn--primary ui-btn--planner"
              onClick={handleStartPlanning}
            >
              Start plannen
            </button>
          </div>
        </div>
      </div>
    </section>
  );
}
