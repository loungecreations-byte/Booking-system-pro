import React, { useMemo, useState } from "react";
import PropTypes from "prop-types";

import { itemConflicts } from "../../day-planner/app/utils/schedule";
import { minutesToTime, snapToStep, timeToMinutes } from "../../day-planner/app/utils/time";
import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";

const DURATION_OPTIONS = [30, 60, 90, 120];
const CATEGORY_OPTIONS = ["eten", "actief", "cultuur", "overig"];
const SORT_OPTIONS = [
  { value: "populair", label: "Populair" },
  { value: "prijs_pp", label: "Prijs p.p." },
  { value: "duur", label: "Duur" },
];

export default function ActivityListMobile({ visible }) {
  const {
    state,
    actions: { setFilters, addActivity, showToast },
  } = usePlanner();

  const [search, setSearch] = useState(state.filters.search || "");
  const [duration, setDuration] = useState(null);
  const [category, setCategory] = useState(null);
  const [sortKey, setSortKey] = useState("populair");

  const hasPlan = state.plan.days.length > 0;

  const filteredProducts = useMemo(() => {
    const normalisedSearch = search.trim().toLowerCase();

    const products = (state.products || []).filter((product) => {
      const title = (product?.title || product?.name || "").toLowerCase();
      const matchesSearch = !normalisedSearch || title.includes(normalisedSearch);

      if (!matchesSearch) {
        return false;
      }

      const minutes = product?.duration_minutes || product?.duration?.minutes || 0;
      if (duration && minutes > duration) {
        return false;
      }

      if (category) {
        const categories =
          (product?.category_slugs && product.category_slugs.map((slug) => slug.toLowerCase())) ||
          (product?.categories && product.categories.map((slug) => slug.toLowerCase())) ||
          [];

        if (!categories.includes(category.toLowerCase())) {
          return false;
        }
      }

      return true;
    });

    if (sortKey === "prijs_pp") {
      return products.slice().sort((a, b) => {
        const priceA = normalisePrice(a);
        const priceB = normalisePrice(b);
        return priceA - priceB;
      });
    }

    if (sortKey === "duur") {
      return products.slice().sort((a, b) => {
        const minutesA = a?.duration_minutes || a?.duration?.minutes || 0;
        const minutesB = b?.duration_minutes || b?.duration?.minutes || 0;
        return minutesA - minutesB;
      });
    }

    return products;
  }, [state.products, search, duration, category, sortKey]);

  const handleSearchChange = (event) => {
    const value = event.target.value;
    setSearch(value);
    setFilters({ ...state.filters, search: value });
  };

  const handleDurationChange = (value) => {
    const next = duration === value ? null : value;
    setDuration(next);
    setFilters({
      ...state.filters,
      duration: next ? String(next) : "all",
    });
  };

  const handleCategoryChange = (value) => {
    const next = category === value ? null : value;
    setCategory(next);
    setFilters({
      ...state.filters,
      category: next || "",
    });
  };

  const handleSortChange = (event) => {
    setSortKey(event.target.value);
    setFilters({
      ...state.filters,
      sort: event.target.value,
    });
  };

  const tryAddActivity = (product) => {
    if (!hasPlan) {
      showToast("Kies eerst een datum en aantal deelnemers.");
      return;
    }

    const startTime = findNextStartTime(product, state.plan, state.config);
    if (!startTime) {
      showToast("Geen beschikbaar tijdslot gevonden. Pas je planning aan.");
      return;
    }

    const success = addActivity(
      { productId: product.id, dayIndex: 0, startTime },
      { locked: false }
    );

    if (!success) {
      showToast("Plaatsen van activiteit is niet gelukt. Probeer een ander tijdslot.");
    }
  };

  const wrapperClass = visible
    ? "sbdp-mobile-panel is-visible"
    : "sbdp-mobile-panel is-hidden";

  return (
    <section className={wrapperClass} aria-hidden={!visible}>
      <header className="sbdp-mobile-panel__header">
        <h3>Activiteiten</h3>
        <p className="sbdp-mobile-panel__intro">
          Voeg activiteiten toe aan je planning. Filters helpen je snel kiezen.
        </p>
      </header>

      <div className="sbdp-mobile-filters">
        <input
          type="search"
          value={search}
          onChange={handleSearchChange}
          placeholder="Zoek op naam..."
          className="sbdp-mobile-filters__search"
        />

        <div className="sbdp-mobile-filters__group">
          {DURATION_OPTIONS.map((option) => (
            <button
              key={option}
              type="button"
              className={
                option === duration
                  ? "sbdp-chip sbdp-chip--active"
                  : "sbdp-chip"
              }
              onClick={() => handleDurationChange(option)}
            >
              &le; {option} min
            </button>
          ))}
        </div>

        <div className="sbdp-mobile-filters__group">
          {CATEGORY_OPTIONS.map((option) => (
            <button
              key={option}
              type="button"
              className={
                option === category
                  ? "sbdp-chip sbdp-chip--active"
                  : "sbdp-chip"
              }
              onClick={() => handleCategoryChange(option)}
            >
              {capitalise(option)}
            </button>
          ))}
        </div>

        <label className="sbdp-mobile-filters__sort">
          <span>Sorteer</span>
          <select value={sortKey} onChange={handleSortChange}>
            {SORT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="sbdp-mobile-activity-strip">
        {filteredProducts.length === 0 ? (
          <p className="sbdp-mobile-activity-strip__empty">
            Geen activiteiten beschikbaar voor deze filters.
          </p>
        ) : (
          filteredProducts.map((product) => (
            <article className="sbdp-mobile-activity-card" key={product.id}>
              {product.image ? (
                <img
                  src={product.image}
                  alt={product.title || product.name}
                  className="sbdp-mobile-activity-card__image"
                  loading="lazy"
                  referrerPolicy="no-referrer"
                />
              ) : null}
              <div className="sbdp-mobile-activity-card__body">
                <h4>{product.title || product.name}</h4>
                <div className="sbdp-mobile-activity-card__meta">
                  {renderDuration(product)}
                  {renderPrice(product)}
                </div>
                {product.categories && product.categories.length ? (
                  <p className="sbdp-mobile-activity-card__categories">
                    {product.categories.join(", ")}
                  </p>
                ) : null}
              </div>
              <button
                type="button"
                className="sbdp-button sbdp-button--ghost"
                onClick={() => tryAddActivity(product)}
              >
                Voeg toe
              </button>
            </article>
          ))
        )}
      </div>
    </section>
  );
}

ActivityListMobile.propTypes = {
  visible: PropTypes.bool,
};

ActivityListMobile.defaultProps = {
  visible: true,
};

function normalisePrice(product) {
  if (typeof product?.price_pp === "number") {
    return product.price_pp;
  }

  if (product?.pricing && typeof product.pricing.per_person === "number") {
    return product.pricing.per_person;
  }

  return 0;
}

function renderDuration(product) {
  const minutes = product?.duration_minutes || product?.duration?.minutes;
  if (!minutes) {
    return null;
  }

  return <span>{minutes} min</span>;
}

function renderPrice(product) {
  const price = normalisePrice(product);
  if (!price) {
    return null;
  }

  const currency = product?.pricing?.currency || product?.currency || "EUR";

  try {
    return (
      <span>
        {new Intl.NumberFormat("nl-NL", {
          style: "currency",
          currency,
        }).format(price)}{" "}
        p.p.
      </span>
    );
  } catch (error) {
    return (
      <span>
        {price.toFixed(2)} {currency} p.p.
      </span>
    );
  }
}

function capitalise(value) {
  if (!value) {
    return "";
  }
  return value.charAt(0).toUpperCase() + value.slice(1);
}

function findNextStartTime(product, plan, config) {
  const step = Math.max(5, parseInt(config?.time_step_minutes, 10) || 30);
  const duration = product?.duration_minutes || product?.duration?.minutes || step;
  const openStart = config?.open_hours?.start || "09:00";
  const openEnd = config?.open_hours?.end || "24:00";

  const dayItems = (plan.items || [])
    .filter((item) => item.dayIndex === 0)
    .slice()
    .sort((a, b) => a.startMinutes - b.startMinutes);

  let candidate = product?.default_start_time || product?.default_start?.time || openStart;
  if (dayItems.length > 0) {
    candidate = dayItems[dayItems.length - 1].endTime;
  }

  let minutes = snapToStep(timeToMinutes(candidate), step);
  const endBoundary = openEnd === "24:00" ? 24 * 60 : timeToMinutes(openEnd);

  while (minutes + duration > endBoundary) {
    minutes -= step;
    if (minutes < timeToMinutes(openStart)) {
      return null;
    }
  }

  while (
    itemConflicts(plan.items, 0, minutes, minutes + duration) &&
    minutes + duration <= endBoundary
  ) {
    minutes += step;
  }

  if (minutes + duration > endBoundary) {
    return null;
  }

  return minutesToTime(minutes);
}
