import React, { useEffect, useMemo, useState } from "react";
import PropTypes from "prop-types";

function FilterPresets({
  personalPresets,
  sharedPresets,
  canManageShared,
  defaultSharedPresetId,
  onApply,
  onSave,
  onDelete,
  onSetDefault,
  loading,
  saving,
  deletingId,
}) {
  const [selectedKey, setSelectedKey] = useState("");
  const [name, setName] = useState("");
  const [saveAsShared, setSaveAsShared] = useState(false);
  const [setAsDefault, setSetAsDefault] = useState(false);

  const personalOptions = useMemo(
    () =>
      (personalPresets || []).map((preset) => ({
        ...preset,
        scope: "personal",
        key: `personal:${preset.id}`,
        is_default: false,
      })),
    [personalPresets]
  );

  const sharedOptions = useMemo(
    () =>
      (sharedPresets || []).map((preset) => ({
        ...preset,
        scope: "shared",
        key: `shared:${preset.id}`,
        is_default: Boolean(preset.is_default),
      })),
    [sharedPresets]
  );

  const combinedOptions = useMemo(() => [...sharedOptions, ...personalOptions], [sharedOptions, personalOptions]);

  useEffect(() => {
    if (selectedKey && !combinedOptions.some((preset) => preset.key === selectedKey)) {
      setSelectedKey("");
    }
  }, [combinedOptions, selectedKey]);

  useEffect(() => {
    if (!selectedKey && defaultSharedPresetId) {
      setSelectedKey(`shared:${defaultSharedPresetId}`);
    }
  }, [defaultSharedPresetId, selectedKey]);

  useEffect(() => {
    if (!canManageShared && saveAsShared) {
      setSaveAsShared(false);
    }
  }, [canManageShared, saveAsShared]);

  useEffect(() => {
    if (!saveAsShared && setAsDefault) {
      setSetAsDefault(false);
    }
  }, [saveAsShared, setAsDefault]);

  const resolvePreset = (key) => combinedOptions.find((preset) => preset.key === key);

  const handleSelect = (event) => {
    const key = event.target.value;
    setSelectedKey(key);
    const preset = resolvePreset(key);
    if (preset) {
      onApply(preset);
    }
  };

  const handleSave = (event) => {
    event.preventDefault();
    const trimmed = name.trim();
    if (!trimmed) {
      return;
    }

    const scope = saveAsShared && canManageShared ? "shared" : "personal";
    onSave(trimmed, { scope, setDefault: saveAsShared && setAsDefault })
      .then((response) => {
        if (response && response.preset && response.preset.id) {
          const presetScope = response.preset.scope || scope;
          setSelectedKey(`${presetScope}:${response.preset.id}`);
        }
        setName("");
        setSetAsDefault(false);
        if (scope === "shared") {
          setSaveAsShared(false);
        }
      })
      .catch(() => {});
  };

  const handleDelete = () => {
    if (!selectedKey) {
      return;
    }

    const preset = resolvePreset(selectedKey);
    if (!preset) {
      return;
    }

    if (preset.scope === "shared" && !canManageShared) {
      return;
    }

    onDelete(preset.id, preset.scope)
      .then(() => {
        setSelectedKey("");
      })
      .catch(() => {});
  };

  const selectedPreset = selectedKey ? resolvePreset(selectedKey) : null;
  const selectedPresetId = selectedPreset ? selectedPreset.id : "";
  const isBusy = loading || saving;
  const isDeleting = deletingId === selectedPresetId;
  const deleteDisabled =
    !selectedPreset || loading || isDeleting || (selectedPreset.scope === "shared" && !canManageShared);
  const setDefaultDisabled =
    !onSetDefault ||
    !canManageShared ||
    !selectedPreset ||
    selectedPreset.scope !== "shared" ||
    selectedPreset.is_default ||
    isBusy;

  return (
    <div className="sbdp-filter-presets">
      <div className="sbdp-filter-presets__row">
        <label htmlFor="sbdp-filter-presets-select" className="sbdp-filter-presets__label">
          Presets
        </label>
        <select
          id="sbdp-filter-presets-select"
          className="sbdp-filter-presets__select"
          value={selectedKey}
          onChange={handleSelect}
          disabled={loading}
        >
          <option value="">{combinedOptions.length === 0 ? "Geen presets" : "Kies een preset"}</option>
          {sharedOptions.length > 0 ? (
            <optgroup label="Team">
          {sharedOptions.map((preset) => (
            <option key={preset.key} value={preset.key}>
              {preset.name}
              {preset.is_default ? " (standaard)" : ""}
            </option>
          ))}
        </optgroup>
      ) : null}
          {personalOptions.length > 0 ? (
            <optgroup label="Mijn presets">
              {personalOptions.map((preset) => (
                <option key={preset.key} value={preset.key}>
                  {preset.name}
                </option>
              ))}
            </optgroup>
          ) : null}
        </select>
        <button type="button" className="button button-secondary" onClick={handleDelete} disabled={deleteDisabled}>
          {isDeleting ? "Verwijderen..." : "Verwijderen"}
        </button>
        {canManageShared && onSetDefault && selectedPreset && selectedPreset.scope === "shared" ? (
          <button
            type="button"
            className="button button-secondary"
            onClick={() => {
              onSetDefault(selectedPreset).catch(() => {});
            }}
            disabled={setDefaultDisabled}
          >
            {selectedPreset.is_default ? "Team standaard" : "Maak standaard"}
          </button>
        ) : null}
      </div>
      <form className="sbdp-filter-presets__row" onSubmit={handleSave}>
        <label htmlFor="sbdp-filter-presets-name" className="sbdp-filter-presets__label">
          Bewaar huidige filters
        </label>
        <input
          id="sbdp-filter-presets-name"
          className="sbdp-filter-presets__input"
          type="text"
          placeholder="Presetnaam"
          value={name}
          onChange={(event) => setName(event.target.value)}
          disabled={isBusy}
        />
        {canManageShared ? (
          <div className="sbdp-filter-presets__actions">
            <label className="sbdp-filter-presets__checkbox">
              <input
                type="checkbox"
                checked={saveAsShared}
                onChange={(event) => setSaveAsShared(event.target.checked)}
                disabled={isBusy}
              />
              <span>Opslaan als team preset</span>
            </label>
            {saveAsShared ? (
              <label className="sbdp-filter-presets__checkbox">
                <input
                  type="checkbox"
                  checked={setAsDefault}
                  onChange={(event) => setSetAsDefault(event.target.checked)}
                  disabled={isBusy}
                />
                <span>Maak standaard</span>
              </label>
            ) : null}
          </div>
        ) : null}
        <button type="submit" className="button button-primary" disabled={isBusy || name.trim() === ""}>
          {saving ? "Opslaan..." : "Opslaan"}
        </button>
      </form>
    </div>
  );
}

FilterPresets.propTypes = {
  personalPresets: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string.isRequired,
      name: PropTypes.string.isRequired,
      filters: PropTypes.object,
      updated_at: PropTypes.string,
    })
  ),
  sharedPresets: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string.isRequired,
      name: PropTypes.string.isRequired,
      filters: PropTypes.object,
      updated_at: PropTypes.string,
    })
  ),
  canManageShared: PropTypes.bool,
  defaultSharedPresetId: PropTypes.string,
  onApply: PropTypes.func.isRequired,
  onSave: PropTypes.func.isRequired,
  onDelete: PropTypes.func.isRequired,
  onSetDefault: PropTypes.func,
  loading: PropTypes.bool,
  saving: PropTypes.bool,
  deletingId: PropTypes.string,
};

FilterPresets.defaultProps = {
  personalPresets: [],
  sharedPresets: [],
  canManageShared: false,
  defaultSharedPresetId: null,
  loading: false,
  saving: false,
  deletingId: "",
  onSetDefault: null,
};

export default FilterPresets;
