import React, { useMemo } from "react";
import PropTypes from "prop-types";
import { __ } from "@wordpress/i18n";

function PresetQuickSwitch({ personalPresets, sharedPresets, onApply, disabled }) {
  const options = useMemo(() => {
    const normalise = (presets, scope) =>
      Array.isArray(presets)
        ? presets.map((preset) => ({
            key: `${scope}:${preset.id}`,
            label: preset.name,
            scope,
            preset,
            updated: preset.updated_at ? Date.parse(preset.updated_at) : 0,
          }))
        : [];

    const combined = [...normalise(sharedPresets, "shared"), ...normalise(personalPresets, "personal")];
    combined.sort((left, right) => right.updated - left.updated);

    return combined.slice(0, 5);
  }, [personalPresets, sharedPresets]);

  if (options.length === 0) {
    return null;
  }

  return (
    <div className="sbdp-quick-preset">
      <label htmlFor="sbdp-quick-preset-select" className="screen-reader-text">
        {__("Quick preset switch", "sbdp")}
      </label>
      <select
        id="sbdp-quick-preset-select"
        className="sbdp-quick-preset__select"
        onChange={(event) => {
          const value = event.target.value;
          if (!value) {
            return;
          }

          const selected = options.find((option) => option.key === value);
          if (selected) {
            onApply(selected.preset);
          }
          event.target.value = "";
        }}
        defaultValue=""
        disabled={disabled}
      >
        <option value="">{__("Snelle preset", "sbdp")}</option>
        {options.map((option) => (
          <option key={option.key} value={option.key}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}

PresetQuickSwitch.propTypes = {
  personalPresets: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string.isRequired,
      name: PropTypes.string.isRequired,
    })
  ),
  sharedPresets: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string.isRequired,
      name: PropTypes.string.isRequired,
    })
  ),
  onApply: PropTypes.func.isRequired,
  disabled: PropTypes.bool,
};

PresetQuickSwitch.defaultProps = {
  personalPresets: [],
  sharedPresets: [],
  disabled: false,
};

export default PresetQuickSwitch;
