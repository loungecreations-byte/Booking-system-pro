import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import PropTypes from "prop-types";

import { getLocalDateIso } from "../utils/time.js";

const Icons = {
  calendar: (
    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
      <path fillRule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clipRule="evenodd" />
    </svg>
  ),
  clock: (
    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clipRule="evenodd" />
    </svg>
  ),
  users: (
    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
      <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z" />
    </svg>
  ),
  heart: (
    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
      <path fillRule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clipRule="evenodd" />
    </svg>
  ),
  sparkle: (
    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
      <path d="M5 2a1 1 0 011 1v1h1a1 1 0 010 2H6v1a1 1 0 01-2 0V6H3a1 1 0 010-2h1V3a1 1 0 011-1zm0 10a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM12 2a1 1 0 01.967.744L14.146 7.2 17.5 9.134a1 1 0 010 1.732l-3.354 1.935-1.18 4.455a1 1 0 01-1.933 0L9.854 12.8 6.5 10.866a1 1 0 010-1.732l3.354-1.935 1.18-4.455A1 1 0 0112 2z" />
    </svg>
  ),
  refresh: (
    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
      <path fillRule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clipRule="evenodd" />
    </svg>
  ),
  chevron: (
    <svg viewBox="0 0 12 12" width="10" height="10">
      <path d="M3 4.5L6 7.5L9 4.5" fill="none" stroke="currentColor" strokeWidth="1.5" />
    </svg>
  ),
};

const DURATION_OPTIONS = [
  { value: "ochtend", label: "Ochtend" },
  { value: "middag", label: "Middag" },
  { value: "avond", label: "Avond" },
  { value: "hele-dag", label: "Hele dag" },
];

const AUDIENCE_OPTIONS = [
  { value: "partner", label: "Partner" },
  { value: "gezin", label: "Gezin" },
  { value: "vrienden", label: "Vrienden" },
  { value: "collegas", label: "Collega's" },
  { value: "solo", label: "Solo" },
];

const VIBE_OPTIONS = [
  { value: "cultuur", label: "Cultuur" },
  { value: "gezellig", label: "Gezellig" },
  { value: "actief", label: "Actief" },
  { value: "romantisch", label: "Romantisch" },
  { value: "verrassend", label: "Verrassend" },
];

const POPOVER_Z_INDEX = 10000;

function formatShortDate(dateStr) {
  if (!dateStr) {
    return "Vandaag";
  }

  try {
    return new Intl.DateTimeFormat("nl-NL", {
      day: "numeric",
      month: "short",
    }).format(new Date(dateStr));
  } catch {
    return dateStr;
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
        top: Math.round(rect.bottom + 6),
        left: Math.round(rect.left),
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

function ChipDropdown({ value, options, onChange, icon }) {
  const [isOpen, setIsOpen] = useState(false);
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const portalTarget = typeof document !== "undefined" ? document.body : null;
  const menuPosition = useAnchoredMenu(isOpen, buttonRef);
  const selected = options.find((option) => option.value === value) || options[0];

  useEffect(() => {
    if (!isOpen) {
      return undefined;
    }

    const handleClickOutside = (event) => {
      const target = event.target;
      if (
        (buttonRef.current && buttonRef.current.contains(target))
        || (menuRef.current && menuRef.current.contains(target))
      ) {
        return;
      }
      setIsOpen(false);
    };

    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [isOpen]);

  return (
    <div ref={buttonRef} className="sbdp-widget-preference-bar__anchor">
      <button
        type="button"
        className={`sbdp-widget-preference-bar__chip ui-chip ${isOpen ? "is-open" : ""}`.trim()}
        onClick={() => setIsOpen((current) => !current)}
        aria-expanded={isOpen}
      >
        <span className="sbdp-widget-preference-bar__chip-icon">{icon}</span>
        <span>{selected?.label}</span>
        <span className="sbdp-widget-preference-bar__chip-chevron">{Icons.chevron}</span>
      </button>
      {isOpen && portalTarget ? createPortal(
        <div
          ref={menuRef}
          className="sbdp-widget-preference-bar__menu"
          style={{
            position: "fixed",
            top: menuPosition.top,
            left: menuPosition.left,
            minWidth: menuPosition.width || 140,
            zIndex: POPOVER_Z_INDEX,
          }}
        >
          {options.map((option) => (
            <button
              key={option.value}
              type="button"
              className={`sbdp-widget-preference-bar__menu-option ${option.value === value ? "is-active" : ""}`.trim()}
              onClick={() => {
                onChange(option.value);
                setIsOpen(false);
              }}
            >
              {option.label}
            </button>
          ))}
        </div>,
        portalTarget
      ) : null}
    </div>
  );
}

export default function WidgetPreferenceBar({
  preferences,
  onPreferenceChange,
  onRegenerate,
  hasItems,
  formDate,
  formParticipants,
  alwaysShow = false,
}) {
  const dateRef = useRef(null);
  const [pendingChanges, setPendingChanges] = useState(null);
  const [countInput, setCountInput] = useState("2");

  const hasPreferences = preferences?.duration || preferences?.audience || preferences?.vibe;
  if (!alwaysShow && !hasPreferences) {
    return null;
  }

  const mergedPreferences = {
    visitDate: preferences?.visitDate || formDate || getLocalDateIso(),
    duration: preferences?.duration || null,
    count: preferences?.count || formParticipants || 2,
    audience: preferences?.audience || null,
    vibe: preferences?.vibe || null,
  };

  const currentPreferences = pendingChanges || mergedPreferences;

  useEffect(() => {
    setCountInput(String(currentPreferences.count || 2));
  }, [currentPreferences.count]);

  const handleChange = (key, value) => {
    const nextPreferences = { ...currentPreferences, [key]: value };
    setPendingChanges(nextPreferences);
    onPreferenceChange(nextPreferences);
  };

  const handleRegenerate = () => {
    if (pendingChanges) {
      onRegenerate(pendingChanges);
      setPendingChanges(null);
      return;
    }

    onRegenerate(preferences);
  };

  const handleCountChange = (event) => {
    const rawValue = event.target.value;
    if (!/^\d*$/.test(rawValue)) {
      return;
    }

    setCountInput(rawValue);

    const nextValue = parseInt(rawValue, 10);
    if (Number.isFinite(nextValue) && nextValue > 0) {
      handleChange("count", nextValue);
    }
  };

  const commitCountChange = () => {
    const nextValue = Math.max(1, parseInt(countInput, 10) || currentPreferences.count || 2);
    setCountInput(String(nextValue));
    handleChange("count", nextValue);
  };

  return (
    <div
      className={`sbdp-widget-preference-bar ${hasItems ? "has-items" : "is-empty"}`.trim()}
      data-component="widget-preference-bar"
    >
      <div className="sbdp-widget-preference-bar__anchor">
        <button
          type="button"
          className="sbdp-widget-preference-bar__chip ui-chip"
          onClick={() => dateRef.current?.showPicker?.()}
        >
          <span className="sbdp-widget-preference-bar__chip-icon">{Icons.calendar}</span>
          <span>{formatShortDate(currentPreferences.visitDate)}</span>
          <span className="sbdp-widget-preference-bar__chip-chevron">{Icons.chevron}</span>
        </button>
        <input
          ref={dateRef}
          type="date"
          value={currentPreferences.visitDate || ""}
          onChange={(event) => handleChange("visitDate", event.target.value)}
          className="sbdp-widget-preference-bar__input-hidden"
        />
      </div>

      <ChipDropdown
        value={currentPreferences.duration}
        options={DURATION_OPTIONS}
        onChange={(value) => handleChange("duration", value)}
        icon={Icons.clock}
      />

      <div className="sbdp-widget-preference-bar__anchor">
        <label className="sbdp-widget-preference-bar__chip ui-chip is-static">
          <span className="sbdp-widget-preference-bar__chip-icon">{Icons.users}</span>
          <input
            type="number"
            min="1"
            max="99"
            inputMode="numeric"
            value={countInput}
            onChange={handleCountChange}
            onBlur={commitCountChange}
            className="sbdp-widget-preference-bar__number"
          />
          <span className="sbdp-widget-preference-bar__suffix">pers.</span>
        </label>
      </div>

      <ChipDropdown
        value={currentPreferences.audience}
        options={AUDIENCE_OPTIONS}
        onChange={(value) => handleChange("audience", value)}
        icon={Icons.heart}
      />

      <ChipDropdown
        value={currentPreferences.vibe}
        options={VIBE_OPTIONS}
        onChange={(value) => handleChange("vibe", value)}
        icon={Icons.sparkle}
      />

      <button
        type="button"
        className="sbdp-widget-preference-bar__refresh ui-btn ui-btn--planner"
        onClick={handleRegenerate}
        title="Plan opnieuw genereren"
      >
        <span className="sbdp-widget-preference-bar__chip-icon">{Icons.refresh}</span>
        <span>Vernieuw</span>
      </button>
    </div>
  );
}

WidgetPreferenceBar.propTypes = {
  preferences: PropTypes.shape({
    visitDate: PropTypes.string,
    duration: PropTypes.string,
    count: PropTypes.number,
    audience: PropTypes.string,
    vibe: PropTypes.string,
  }),
  onPreferenceChange: PropTypes.func.isRequired,
  onRegenerate: PropTypes.func.isRequired,
  hasItems: PropTypes.bool,
  formDate: PropTypes.string,
  formParticipants: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  alwaysShow: PropTypes.bool,
};

WidgetPreferenceBar.defaultProps = {
  preferences: {},
  hasItems: false,
  formDate: null,
  formParticipants: 2,
  alwaysShow: false,
};
