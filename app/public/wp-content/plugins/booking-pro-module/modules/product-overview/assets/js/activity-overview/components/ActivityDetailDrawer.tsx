import React from "react";
import type { Activity } from "../types";

interface ActivityDetailDrawerProps {
  activity: Activity | null;
  onClose: () => void;
}

export default function ActivityDetailDrawer({ activity, onClose }: ActivityDetailDrawerProps) {
  if (!activity) {
    return null;
  }

  return (
    <>
      <div className="ao-drawer-backdrop" onClick={onClose} />
      <aside className="ao-drawer" aria-live="polite">
        <header className="ao-drawer__header">
          <div>
            <p className="ao-drawer__eyebrow">Details</p>
            <h2 className="ao-drawer__title">{activity.title}</h2>
          </div>
          <button type="button" className="ao-drawer__close" aria-label="Sluit details" onClick={onClose}>
            ×
          </button>
        </header>

        <p className="ao-drawer__excerpt">{activity.excerpt || "Mooie activiteit zonder extra toelichting."}</p>

        <dl className="ao-drawer__meta">
          <div>
            <dt>Status</dt>
            <dd>{activity.statusLabel}</dd>
          </div>
          <div>
            <dt>Type</dt>
            <dd>{activity.primaryTypeLabel || "Activiteit"}</dd>
          </div>
          <div>
            <dt>Duur</dt>
            <dd>{activity.durationLabel}</dd>
          </div>
          <div>
            <dt>Locatie</dt>
            <dd>{activity.locationLabel || "Den Bosch"}</dd>
          </div>
          <div>
            <dt>Prijs</dt>
            <dd>{activity.priceLabel}</dd>
          </div>
        </dl>

        {activity.tags.length > 0 ? (
          <div className="ao-drawer__tags">
            {activity.tags.map((tag) => (
              <span key={tag} className="ao-chip">
                {tag}
              </span>
            ))}
          </div>
        ) : null}

        <footer className="ao-drawer__actions">
          {activity.permalink ? (
            <a className="ui-btn ui-btn--secondary ao-button--full" href={activity.permalink}>
              {activity.isRequestOnly ? "Bekijk aanvraag" : "Bekijk activiteit"}
            </a>
          ) : null}
          <a className="ui-btn ui-btn--primary ui-btn--planner ao-button--full" href={activity.plannerHref}>
            {activity.isRequestOnly ? "Voeg aanvraag toe aan planner" : "Voeg direct toe aan planner"}
          </a>
        </footer>
      </aside>
    </>
  );
}
