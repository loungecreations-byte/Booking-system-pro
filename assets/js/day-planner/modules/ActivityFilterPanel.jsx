import React, { useEffect, useState } from "react";
import PropTypes from "prop-types";

const DEFAULT_FILTERS = {
  search: "",
  category: "",
  priceMin: "",
  priceMax: "",
  onlyAvailable: false,
};

function ActivityFilterPanel({ filters, onChange }) {
  const [form, setForm] = useState(() => ({
    ...DEFAULT_FILTERS,
    ...normaliseIncoming(filters),
  }));

  useEffect(() => {
    setForm({
      ...DEFAULT_FILTERS,
      ...normaliseIncoming(filters),
    });
  }, [filters]);

  const handleChange = (event) => {
    const { name, value, type, checked } = event.target;
    setForm((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const applyFilters = (event) => {
    event.preventDefault();
    onChange(normaliseOutgoing(form));
  };

  const resetFilters = () => {
    setForm({ ...DEFAULT_FILTERS });
    onChange({});
  };

  return (
    <aside className="sbdp-day-planner__filters">
      <h3>Filters</h3>
      <form onSubmit={applyFilters}>
        <label className="sbdp-filter">
          <span>Zoekterm</span>
          <input
            type="search"
            name="search"
            value={form.search}
            onChange={handleChange}
            placeholder="High tea, rondvaart..."
          />
        </label>

        <label className="sbdp-filter">
          <span>Categorie</span>
          <input
            type="text"
            name="category"
            value={form.category}
            onChange={handleChange}
            placeholder="Eten & drinken"
          />
        </label>

        <div className="sbdp-filter sbdp-filter--row">
          <label>
            <span>Min. prijs</span>
            <input
              type="number"
              min="0"
              name="priceMin"
              value={form.priceMin}
              onChange={handleChange}
              placeholder="0"
            />
          </label>
          <label>
            <span>Max. prijs</span>
            <input
              type="number"
              min="0"
              name="priceMax"
              value={form.priceMax}
              onChange={handleChange}
              placeholder="250"
            />
          </label>
        </div>

        <label className="sbdp-filter sbdp-filter--checkbox">
          <input
            type="checkbox"
            name="onlyAvailable"
            checked={form.onlyAvailable}
            onChange={handleChange}
          />
          <span>Alleen beschikbare activiteiten</span>
        </label>

        <div className="sbdp-filter__actions">
          <button type="submit" className="button button-primary">
            Toepassen
          </button>
          <button type="button" className="button" onClick={resetFilters}>
            Reset
          </button>
        </div>
      </form>
    </aside>
  );
}

ActivityFilterPanel.propTypes = {
  filters: PropTypes.object.isRequired,
  onChange: PropTypes.func.isRequired,
};

function normaliseIncoming(current) {
  if (!current) {
    return {};
  }

  return {
    search: current.search || "",
    category: Array.isArray(current.category)
      ? current.category.join(", ")
      : current.category || "",
    priceMin: current.price_min ?? current.priceMin ?? "",
    priceMax: current.price_max ?? current.priceMax ?? "",
    onlyAvailable: Boolean(current.only_available ?? current.onlyAvailable),
  };
}

function normaliseOutgoing(form) {
  const payload = {};
  const search = form.search.trim();
  const category = form.category.trim();
  const priceMinValue = form.priceMin !== "" ? Number(form.priceMin) : "";
  const priceMaxValue = form.priceMax !== "" ? Number(form.priceMax) : "";

  if (search) {
    payload.search = search;
  }

  if (category) {
    payload.category = category.split(",").map((item) => item.trim()).filter(Boolean);
  }

  if (!Number.isNaN(priceMinValue) && priceMinValue !== "") {
    payload.price_min = priceMinValue;
  }

  if (!Number.isNaN(priceMaxValue) && priceMaxValue !== "") {
    payload.price_max = priceMaxValue;
  }

  if (form.onlyAvailable) {
    payload.only_available = true;
  }

  return payload;
}

export default ActivityFilterPanel;
