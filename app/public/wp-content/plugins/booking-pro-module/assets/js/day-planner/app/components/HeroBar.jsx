import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

import { usePlanner } from "../../store/PlannerProvider.jsx";
import { getLocalDateIso } from "../utils/time.js";

const DURATION_OPTIONS = [
  { value: "ochtend", label: "Ochtend", icon: "🌅" },
  { value: "middag", label: "Middag", icon: "☀️" },
  { value: "avond", label: "Avond", icon: "🌙" },
  { value: "hele-dag", label: "Hele dag", icon: "📅" },
];

const AUDIENCE_OPTIONS = [
  { value: "partner", label: "Partner", icon: "💑" },
  { value: "gezin", label: "Gezin", icon: "👨‍👩‍👧‍👦" },
  { value: "vrienden", label: "Vrienden", icon: "👥" },
  { value: "collegas", label: "Collega's", icon: "💼" },
  { value: "solo", label: "Solo", icon: "🧍" },
];

const VIBE_OPTIONS = [
  { value: "cultuur", label: "Cultuur", icon: "🎭" },
  { value: "gezellig", label: "Gezellig", icon: "🍻" },
  { value: "actief", label: "Actief", icon: "🚴" },
  { value: "romantisch", label: "Romantisch", icon: "💕" },
  { value: "verrassend", label: "Verrassend", icon: "✨" },
];

const POPOVER_Z_INDEX = 10000;

function useReducedMotion() {
  return (
    typeof window !== "undefined"
    && window.matchMedia
    && window.matchMedia("(prefers-reduced-motion: reduce)").matches
  );
}

function formatDisplayDate(value) {
  if (!value) {
    return "Kies datum";
  }

  try {
    return new Intl.DateTimeFormat("nl-NL", {
      weekday: "short",
      day: "numeric",
      month: "short",
    }).format(new Date(value));
  } catch {
    return value;
  }
}

function useAnchoredMenu(isOpen, anchorRef) {
  const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0, width: 0 });

  useEffect(() => {
    if (!isOpen || !anchorRef.current) {
      return undefined;
    }

    const updatePosition = () => {
      const rect = anchorRef.current.getBoundingClientRect();
      setMenuPosition({
        top: Math.round(rect.bottom + 8),
        left: Math.round(rect.left + rect.width / 2),
        width: Math.round(rect.width),
      });
    };

    updatePosition();
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true);

    return () => {
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
    };
  }, [anchorRef, isOpen]);

  return menuPosition;
}

function DatePicker({ value, onChange }) {
  const [open, setOpen] = useState(false);
  const [viewDate, setViewDate] = useState(() => (value ? new Date(value) : new Date()));
  const anchorRef = useRef(null);
  const menuRef = useRef(null);
  const portalTarget = typeof document !== "undefined" ? document.body : null;
  const menuPosition = useAnchoredMenu(open, anchorRef);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    const close = (event) => {
      const target = event.target;
      if (
        (anchorRef.current && anchorRef.current.contains(target))
        || (menuRef.current && menuRef.current.contains(target))
      ) {
        return;
      }
      setOpen(false);
    };

    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [open]);

  const getDaysInMonth = (date) => {
    const year = date.getFullYear();
    const month = date.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const daysInMonth = lastDay.getDate();
    const startDayOfWeek = (firstDay.getDay() + 6) % 7;
    const days = [];

    for (let i = 0; i < startDayOfWeek; i += 1) {
      days.push(null);
    }

    for (let i = 1; i <= daysInMonth; i += 1) {
      days.push(new Date(year, month, i));
    }

    return days;
  };

  const monthName = viewDate.toLocaleDateString("nl-NL", { month: "long", year: "numeric" });
  const days = getDaysInMonth(viewDate);
  const weekDays = ["Ma", "Di", "Wo", "Do", "Vr", "Za", "Zo"];
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const selectedDate = value ? new Date(value) : null;
  if (selectedDate) {
    selectedDate.setHours(0, 0, 0, 0);
  }

  const selectDay = (day) => {
    if (!day || day < today) {
      return;
    }

    onChange(day.toISOString().split("T")[0]);
    setOpen(false);
  };

  return (
    <div ref={anchorRef} className="sbdp-hero-bar__anchor">
      <button
        type="button"
        className={`sbdp-hero-bar__chip ui-chip ${value ? "is-filled" : ""} ${open ? "is-open" : ""}`.trim()}
        onClick={() => setOpen((current) => !current)}
      >
        <span className="sbdp-hero-bar__chip-icon">📅</span>
        <span>{formatDisplayDate(value)}</span>
        <span className="sbdp-hero-bar__chip-chevron">▼</span>
      </button>
      {open && portalTarget ? createPortal(
        <div
          ref={menuRef}
          className="sbdp-hero-bar__dropdown sbdp-hero-bar__dropdown--calendar"
          style={{
            position: "fixed",
            top: menuPosition.top,
            left: menuPosition.left,
            transform: "translateX(-50%)",
            zIndex: POPOVER_Z_INDEX,
          }}
        >
          <div className="sbdp-hero-bar__calendar-header">
            <button
              type="button"
              className="sbdp-hero-bar__calendar-nav ui-btn ui-btn--ghost ui-btn--icon"
              onClick={() => setViewDate(new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1))}
            >
              ◀
            </button>
            <span className="sbdp-hero-bar__calendar-month">{monthName}</span>
            <button
              type="button"
              className="sbdp-hero-bar__calendar-nav ui-btn ui-btn--ghost ui-btn--icon"
              onClick={() => setViewDate(new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1))}
            >
              ▶
            </button>
          </div>
          <div className="sbdp-hero-bar__calendar-weekdays">
            {weekDays.map((dayLabel) => (
              <div key={dayLabel} className="sbdp-hero-bar__calendar-weekday">{dayLabel}</div>
            ))}
          </div>
          <div className="sbdp-hero-bar__calendar-grid">
            {days.map((day, index) => {
              if (!day) {
                return <div key={`empty-${index}`} className="sbdp-hero-bar__calendar-day is-empty" />;
              }

              const isPast = day < today;
              const isToday = day.getTime() === today.getTime();
              const isSelected = selectedDate && day.getTime() === selectedDate.getTime();

              return (
                <button
                  key={day.toISOString()}
                  type="button"
                  className={`sbdp-hero-bar__calendar-day ${isPast ? "is-past" : ""} ${isToday ? "is-today" : ""} ${isSelected ? "is-selected" : ""}`.trim()}
                  onClick={() => selectDay(day)}
                  disabled={isPast}
                >
                  {day.getDate()}
                </button>
              );
            })}
          </div>
        </div>,
        portalTarget
      ) : null}
    </div>
  );
}

function GroupCounter({ value, onChange }) {
  const count = value || 2;

  return (
    <div className="sbdp-hero-bar__counter" aria-label={`Aantal deelnemers: ${count}`}>
      <button
        type="button"
        className="sbdp-hero-bar__counter-btn ui-btn ui-btn--secondary ui-btn--icon"
        onClick={() => onChange(Math.max(1, count - 1))}
        aria-label="Minder deelnemers"
      >
        −
      </button>
      <div className="sbdp-hero-bar__counter-value">
        <strong>{count}</strong> personen
      </div>
      <button
        type="button"
        className="sbdp-hero-bar__counter-btn ui-btn ui-btn--secondary ui-btn--icon"
        onClick={() => onChange(Math.min(20, count + 1))}
        aria-label="Meer deelnemers"
      >
        +
      </button>
    </div>
  );
}

function ChipDropdown({ value, options, onChange, label, icon }) {
  const [open, setOpen] = useState(false);
  const anchorRef = useRef(null);
  const menuRef = useRef(null);
  const portalTarget = typeof document !== "undefined" ? document.body : null;
  const menuPosition = useAnchoredMenu(open, anchorRef);
  const selected = options.find((option) => option.value === value);
  const displayLabel = selected ? selected.label : label;
  const displayIcon = selected ? selected.icon : icon;

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    const close = (event) => {
      const target = event.target;
      if (
        (anchorRef.current && anchorRef.current.contains(target))
        || (menuRef.current && menuRef.current.contains(target))
      ) {
        return;
      }
      setOpen(false);
    };

    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [open]);

  return (
    <div ref={anchorRef} className="sbdp-hero-bar__anchor">
      <button
        type="button"
        className={`sbdp-hero-bar__chip ui-chip ${selected ? "is-filled" : ""} ${open ? "is-open" : ""}`.trim()}
        onClick={() => setOpen((current) => !current)}
        onKeyDown={(event) => {
          if (event.key === "Escape") {
            setOpen(false);
          }
        }}
      >
        <span className="sbdp-hero-bar__chip-icon">{displayIcon}</span>
        <span>{displayLabel}</span>
        <span className="sbdp-hero-bar__chip-chevron">▼</span>
      </button>
      {open && portalTarget ? createPortal(
        <div
          ref={menuRef}
          className="sbdp-hero-bar__dropdown"
          style={{
            position: "fixed",
            top: menuPosition.top,
            left: menuPosition.left,
            minWidth: Math.max(160, menuPosition.width || 0),
            transform: "translateX(-50%)",
            zIndex: POPOVER_Z_INDEX,
          }}
        >
          {options.map((option) => {
            const isActive = option.value === value;
            return (
              <button
                key={option.value}
                type="button"
                className={`sbdp-hero-bar__option ${isActive ? "is-active" : ""}`.trim()}
                onClick={() => {
                  onChange(option.value);
                  setOpen(false);
                }}
              >
                <span className="sbdp-hero-bar__option-icon">{option.icon}</span>
                <span>{option.label}</span>
                {isActive ? <span className="sbdp-hero-bar__option-check">✓</span> : null}
              </button>
            );
          })}
        </div>,
        portalTarget
      ) : null}
    </div>
  );
}

export default function HeroBar({ onExpand }) {
  const { state, actions } = usePlanner();
  const [isRefreshing, setIsRefreshing] = useState(false);
  const prefersReducedMotion = useReducedMotion();
  const { widgetPreferences, form, plan } = state;
  const visitDate = widgetPreferences?.visitDate || form?.date || null;
  const parsedFormCount = Number.parseInt(form?.participants, 10);
  const parsedPlanCount = Number.parseInt(plan?.participants, 10);
  const parsedWidgetCount = Number.parseInt(widgetPreferences?.count, 10);
  const count =
    (Number.isFinite(parsedFormCount) && parsedFormCount > 0 ? parsedFormCount : null) ||
    (Number.isFinite(parsedPlanCount) && parsedPlanCount > 0 ? parsedPlanCount : null) ||
    (Number.isFinite(parsedWidgetCount) && parsedWidgetCount > 0 ? parsedWidgetCount : null) ||
    2;
  const duration = widgetPreferences?.duration || null;
  const audience = widgetPreferences?.audience || null;
  const vibe = widgetPreferences?.vibe || null;

  const updatePreference = (key, nextValue) => {
    const nextPreferences = {
      ...widgetPreferences,
      [key]: nextValue,
    };

    actions.setWidgetPreferences(nextPreferences);

    if (key === "visitDate" && actions.setFormField) {
      actions.setFormField("date", nextValue);
    }

    if (key === "count" && actions.setFormField) {
      actions.setFormField("participants", String(nextValue));
    }
  };

  const handleRefresh = async () => {
    if (isRefreshing) {
      return;
    }

    setIsRefreshing(true);

    try {
      const preferences = {
        visitDate: visitDate || getLocalDateIso(),
        count: count || 2,
        duration: duration || "hele-dag",
        audience: audience || "vrienden",
        vibe: vibe || "verrassend",
      };

      if (actions.regeneratePlan) {
        await actions.regeneratePlan(preferences);
      }
    } catch (error) {
      console.error("Refresh failed:", error);
    } finally {
      window.setTimeout(() => setIsRefreshing(false), 800);
    }
  };

  const programStatusLabel = Array.isArray(plan?.items) && plan.items.length > 0
    ? `${plan.items.length} onderdelen in je programma`
    : "Nog geen programma opgebouwd";

  return (
    <div
      className={`sbdp-hero-bar ${prefersReducedMotion ? "is-reduced-motion" : ""}`.trim()}
      data-herobar="v13"
    >
      <DatePicker
        value={visitDate}
        onChange={(nextValue) => updatePreference("visitDate", nextValue)}
      />

      <GroupCounter
        value={count}
        onChange={(nextValue) => updatePreference("count", nextValue)}
      />

      <ChipDropdown
        value={duration}
        options={DURATION_OPTIONS}
        onChange={(nextValue) => updatePreference("duration", nextValue)}
        label="Dagdeel"
        icon="🕐"
      />

      <ChipDropdown
        value={audience}
        options={AUDIENCE_OPTIONS}
        onChange={(nextValue) => updatePreference("audience", nextValue)}
        label="Met wie"
        icon="❤️"
      />

      <ChipDropdown
        value={vibe}
        options={VIBE_OPTIONS}
        onChange={(nextValue) => updatePreference("vibe", nextValue)}
        label="Sfeer"
        icon="✨"
      />

      <div className="sbdp-hero-bar__status ui-chip" aria-label="Programmastatus">
        <span className="sbdp-hero-bar__status-icon">▶</span>
        <span>{programStatusLabel}</span>
      </div>

      <button
        type="button"
        className={`sbdp-hero-bar__refresh ui-btn ui-btn--planner ${isRefreshing ? "is-loading" : ""}`.trim()}
        onClick={handleRefresh}
      >
        <span className="sbdp-hero-bar__refresh-icon">🔄</span>
        <span>{isRefreshing ? "Laden..." : "Herbereken"}</span>
      </button>

      {typeof onExpand === "function" ? (
        <button
          type="button"
          className="sbdp-hero-bar__expand ui-btn ui-btn--ghost ui-btn--inline"
          onClick={onExpand}
          aria-label="Toon uitgebreide hero"
        >
          <span className="sbdp-hero-bar__expand-icon">+</span>
          <span>Uitklappen</span>
        </button>
      ) : null}
    </div>
  );
}
