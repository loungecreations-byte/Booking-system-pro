import React from "react";
import type { Activity } from "../types";

interface TopPicksStripProps {
  activities: Activity[];
}

export default function TopPicksStrip({ activities }: TopPicksStripProps) {
  if (activities.length === 0) {
    return null;
  }

  return (
    <section className="ao-top-picks ui-panel ui-panel--soft">
      <header className="ao-top-picks__header">
        <div>
          <p className="ao-top-picks__eyebrow">Top picks</p>
          <h3 className="ao-top-picks__title">Direct klaar om te plannen</h3>
        </div>
        <p className="ao-top-picks__hint">Geselecteerd op populariteit en beschikbaarheid.</p>
      </header>
      <div className="ao-top-picks__list" role="list">
        {activities.map((activity) => (
          <article key={activity.id} className="ao-top-pick" role="listitem">
            <div className="ao-top-pick__content">
              <p className="ao-top-pick__name">{activity.title}</p>
              <p className="ao-top-pick__meta">
                {activity.durationLabel}
                <span aria-hidden="true" className="ao-top-pick__separator">
                  •
                </span>
                {activity.priceLabel}
              </p>
            </div>
            <div className="ao-top-pick__actions">
              <a
                className="ui-btn ui-btn--primary ao-button--pill"
                href={`/plan-je-dag?start=${encodeURIComponent(activity.planSlug)}`}
              >
                Plan hier
              </a>
            </div>
          </article>
        ))}
      </div>
    </section>
  );
}
