import React from "react";
import type { FilterKey } from "../types";

interface Option {
  value: string;
  label: string;
}

interface ActivityFilterBarProps {
  selectedType: string;
  selectedNeighborhood: string;
  typeOptions: Option[];
  neighborhoodOptions: Option[];
  onTypeChange: (value: string) => void;
  onNeighborhoodChange: (value: string) => void;
  // Legacy props retained for call-site compatibility
  filters?: unknown;
  selected?: Set<FilterKey>;
  onToggle?: unknown;
  bookableOnly?: boolean;
  onToggleBookable?: () => void;
  resultCount?: number;
  activeCount?: number;
  search?: string;
  onSearchChange?: (value: string) => void;
  onReset?: () => void;
  onSubmit?: () => void;
  isOpen?: boolean;
  onToggleOpen?: () => void;
  contextDate?: string;
  contextParticipants?: number | null;
  pendingContextDate?: string;
  pendingContextParticipants?: number | null;
  onContextDateChange?: (value: string) => void;
  onContextParticipantsChange?: (value: number | null) => void;
  onApplyContext?: () => void;
}

export default function ActivityFilterBar({
  resultCount = 0,
  search = "",
  selectedType,
  selectedNeighborhood,
  onReset,
  contextDate,
  contextParticipants,
  pendingContextDate,
  pendingContextParticipants,
  onContextDateChange,
  onContextParticipantsChange,
  onApplyContext,
}: ActivityFilterBarProps) {
  const formattedDate = formatDateLabel(contextDate);
  const pendingFormattedDate = formatDateLabel(pendingContextDate) || "Kies datum";
  const participantLabel =
    typeof contextParticipants === "number" && Number.isFinite(contextParticipants) && contextParticipants > 0
      ? `${contextParticipants} ${contextParticipants === 1 ? "deelnemer" : "deelnemers"}`
      : "";

  const activeFilters = [search.trim(), selectedType.trim(), selectedNeighborhood.trim()].filter((value) => value !== "");
  const pendingParticipantLabel =
    typeof pendingContextParticipants === "number" && Number.isFinite(pendingContextParticipants) && pendingContextParticipants > 0
      ? String(pendingContextParticipants)
      : "";
  const pendingParticipantValue =
    typeof pendingContextParticipants === "number" && Number.isFinite(pendingContextParticipants) && pendingContextParticipants > 0
      ? pendingContextParticipants
      : 1;

  const handleParticipantDelta = (delta: number) => {
    onContextParticipantsChange?.(Math.max(1, pendingParticipantValue + delta));
  };

  return (
    <section className="sbdp-hero-compact sbdp-hero-compact--controls-only sbdp-hero-compact--one-line" aria-label="Actuele selectie">
      <div className="sbdp-pb" role="group" aria-label="Datum en deelnemers">
        <label className="sbdp-pb-field">
          <span className="sbdp-pb-badge">1</span>
          <span className="sbdp-pb-content">
            <span className="sbdp-pb-label">Kies datum</span>
            <span className="sbdp-pb-value">{pendingFormattedDate}</span>
          </span>
          <span className="sbdp-pb-chevron" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
              <line x1="16" y1="2" x2="16" y2="6" />
              <line x1="8" y1="2" x2="8" y2="6" />
              <line x1="3" y1="10" x2="21" y2="10" />
            </svg>
          </span>
          <input
            className="sbdp-pb-overlay"
            type="date"
            value={pendingContextDate || ""}
            onChange={(event) => onContextDateChange?.(event.target.value)}
          />
        </label>

        <div className="sbdp-pb-divider" />

        <div className="sbdp-pb-field">
          <span className="sbdp-pb-badge">2</span>
          <span className="sbdp-pb-content">
            <span className="sbdp-pb-label">Aantal personen</span>
            <span className="sbdp-pb-stepper">
              <button
                type="button"
                className="sbdp-pb-stepper-btn"
                aria-label="Verlaag deelnemers"
                disabled={pendingParticipantValue <= 1}
                onClick={() => handleParticipantDelta(-1)}
              >
                -
              </button>
              <input
                className="sbdp-pb-stepper-val"
                type="number"
                min={1}
                step={1}
                inputMode="numeric"
                aria-label="Aantal deelnemers"
                value={pendingParticipantLabel}
                onChange={(event) => {
                  const next = Number.parseInt(event.target.value, 10);
                  onContextParticipantsChange?.(Number.isFinite(next) && next > 0 ? next : null);
                }}
              />
              <button
                type="button"
                className="sbdp-pb-stepper-btn"
                aria-label="Verhoog deelnemers"
                onClick={() => handleParticipantDelta(1)}
              >
                +
              </button>
            </span>
          </span>
        </div>

        <div className="sbdp-pb-divider" />

        <div className="sbdp-pb-field">
          <span className="sbdp-pb-badge">3</span>
          <span className="sbdp-pb-content">
            <span className="sbdp-pb-label">Resultaten</span>
            <span className="sbdp-pb-value">
              {[formattedDate, participantLabel, `${resultCount} resultaten`].filter(Boolean).join(" · ")}
            </span>
          </span>
        </div>

        <div className="sbdp-pb-divider" />

        <div className="sbdp-pb-field sbdp-pb-field--action">
          <button type="button" className="ui-btn ui-btn--planner sbdp-pb-cta" onClick={onApplyContext}>
            Toon aanbod
          </button>
        </div>
      </div>

      {activeFilters.length > 0 ? (
        <div className="ao-filter-bar__footer">
          <p className="ao-filter-bar__hint">
            Extra filters actief: {activeFilters.join(" · ")}
          </p>
          {onReset ? (
            <button type="button" className="ao-toggle" onClick={onReset}>
              Wis lokale filters
            </button>
          ) : null}
        </div>
      ) : null}
    </section>
  );
}

function formatDateLabel(value?: string): string {
  if (!value) {
    return "";
  }

  const parsed = new Date(`${value}T00:00:00`);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("nl-NL", {
    weekday: "short",
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(parsed);
}
