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
  const participantLabel =
    typeof contextParticipants === "number" && Number.isFinite(contextParticipants) && contextParticipants > 0
      ? `${contextParticipants} ${contextParticipants === 1 ? "deelnemer" : "deelnemers"}`
      : "";

  const activeFilters = [search.trim(), selectedType.trim(), selectedNeighborhood.trim()].filter((value) => value !== "");
  const pendingParticipantsValue =
    typeof pendingContextParticipants === "number" && Number.isFinite(pendingContextParticipants) && pendingContextParticipants > 0
      ? String(pendingContextParticipants)
      : "";

  return (
    <section className="ao-filter-panel" aria-label="Actuele selectie">
      <div className="ao-filter-bar">
        <div className="ao-filter-bar__header">
          <div>
            <p className="ao-filter-bar__eyebrow">Jouw selectie</p>
            <h2 className="ao-filter-bar__title">Activiteiten voor jouw dag</h2>
          </div>
          <div className="ao-filter-bar__summary" aria-live="polite">
            <span>{formattedDate || "Kies een datum in de widget of planner"}</span>
            {participantLabel ? <span>{participantLabel}</span> : null}
            <span>{resultCount} resultaten</span>
          </div>
        </div>

        <div className="ao-filter-bar__fields" aria-label="Datum en deelnemers">
          <label className="ao-filter-field">
            <span>Datum</span>
            <input
              className="ao-filter-input"
              type="date"
              value={pendingContextDate || ""}
              onChange={(event) => onContextDateChange?.(event.target.value)}
            />
          </label>
          <label className="ao-filter-field">
            <span>Deelnemers</span>
            <input
              className="ao-filter-input"
              type="number"
              min={1}
              step={1}
              inputMode="numeric"
              value={pendingParticipantsValue}
              onChange={(event) => {
                const next = Number.parseInt(event.target.value, 10);
                onContextParticipantsChange?.(Number.isFinite(next) && next > 0 ? next : null);
              }}
            />
          </label>
          <div className="ao-filter-bar__actions">
            <button type="button" className="ui-btn ui-btn--primary" onClick={onApplyContext}>
              Toon activiteiten
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
      </div>
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
