import React, { useMemo, useState } from "react";
import PropTypes from "prop-types";

const PAGE_SIZE = 6;
const ACTIVITY_MIME = "application/x-sbdp-activity";

const formatCurrency = (value, currency) => {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return null;
  }

  try {
    return new Intl.NumberFormat("nl-NL", {
      style: "currency",
      currency: currency || "EUR",
      minimumFractionDigits: 2,
    }).format(value);
  } catch (error) {
    const symbol = currency === "EUR" || !currency ? "€" : `${currency} `;
    return `${symbol}${value.toFixed(2)}`;
  }
};

function ActivityList({ activities, onAdd, loading }) {
  const [visibleCount, setVisibleCount] = useState(PAGE_SIZE);

  const [visibleActivities, hasMore] = useMemo(() => {
    if (!Array.isArray(activities)) {
      return [[], false];
    }

    const slice = activities.slice(0, visibleCount);

    return [slice, visibleCount < activities.length];
  }, [activities, visibleCount]);

  const handleLoadMore = () => {
    setVisibleCount((count) => Math.min(count + PAGE_SIZE, activities.length));
  };

  const handleDragStart = (event, activity) => {
    event.dataTransfer.effectAllowed = "copy";
    event.dataTransfer.setData(ACTIVITY_MIME, JSON.stringify(activity));
    event.dataTransfer.setData("text/plain", activity.title || "Activiteit");
  };

  return (
    <section className="sbdp-day-planner__activities">
      <header className="sbdp-day-planner__section-heading">
        <h3>Beschikbare activiteiten</h3>
        <p>Kies een activiteit om deze aan je planning toe te voegen of sleep naar een dag.</p>
      </header>
      {loading ? (
        <p>Activiteiten laden…</p>
      ) : activities.length === 0 ? (
        <p>Er zijn geen activiteiten gevonden voor deze filters.</p>
      ) : (
        <>
          <ul className="sbdp-activity-grid">
            {visibleActivities.map((activity) => (
              <li
                key={activity.id}
                className="sbdp-activity"
                draggable
                onDragStart={(event) => handleDragStart(event, activity)}
              >
                {activity.image ? (
                  <img src={activity.image} alt={activity.title} className="sbdp-activity__image" />
                ) : null}
                <div className="sbdp-activity__body">
                  <strong className="sbdp-activity__title">{activity.title}</strong>
                  {typeof activity.price_pp === "number" && !Number.isNaN(activity.price_pp) ? (
                    <span className="sbdp-activity__price">
                      {formatCurrency(activity.price_pp, activity.currency)} p.p.
                    </span>
                  ) : null}
                  {typeof activity.duration_minutes === "number" && activity.duration_minutes > 0 ? (
                    <small className="sbdp-activity__duration">{activity.duration_minutes} min.</small>
                  ) : null}
                  {Array.isArray(activity.categories) && activity.categories.length > 0 ? (
                    <small className="sbdp-activity__categories">{activity.categories.join(", ")}</small>
                  ) : null}
                  <div className="sbdp-activity__actions">
                    <button type="button" onClick={() => onAdd(activity)}>
                      Voeg toe
                    </button>
                    {activity.permalink ? (
                      <a href={activity.permalink} target="_blank" rel="noreferrer">
                        Bekijk
                      </a>
                    ) : null}
                  </div>
                </div>
              </li>
            ))}
          </ul>
          {hasMore ? (
            <button
              type="button"
              className="button button-secondary sbdp-activity__load-more"
              onClick={handleLoadMore}
            >
              Toon meer activiteiten
            </button>
          ) : null}
        </>
      )}
    </section>
  );
}

ActivityList.propTypes = {
  activities: PropTypes.arrayOf(PropTypes.object).isRequired,
  onAdd: PropTypes.func.isRequired,
  loading: PropTypes.bool,
};

ActivityList.defaultProps = {
  loading: false,
};

export default ActivityList;
