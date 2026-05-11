import React from "react";
import type { Activity } from "../types";

interface ActivityCardProps {
  activity: Activity;
  onSelect?: (activity: Activity) => void;
  isSaved?: boolean;
  isSelected?: boolean;
  onToggleSave?: (activity: Activity) => void;
  variant?: "default" | "archive";
  ctaLabel?: string;
}

export default function ActivityCard({
  activity,
  isSaved = false,
  isSelected = false,
  onToggleSave,
  variant = "default",
}: ActivityCardProps) {
  const hasImage = typeof activity.image === "string" && activity.image.trim() !== "";

  return (
    <article
      className="ui-card ao-spot-card"
      data-card-variant={variant}
      data-card-state={isSaved ? "saved" : undefined}
      data-card-active={isSelected ? "true" : undefined}
      aria-current={isSelected ? "true" : undefined}
    >
      <a className="ao-spot-card__link" href={activity.permalink}>
        <div className="ao-spot-card__media">
          {hasImage ? (
            <img className="ao-spot-card__image" src={activity.image} alt={activity.title} loading="lazy" />
          ) : (
            <span className="ao-spot-card__placeholder" aria-hidden="true" />
          )}
        </div>

        <div className="ao-spot-card__body">
          <div className="ao-spot-card__header">
            <div>
              <p className="ao-spot-card__eyebrow">{activity.statusLabel}</p>
              <h3 className="ao-spot-card__title">{activity.title}</h3>
            </div>
          </div>
        </div>
      </a>

      <div className="ao-spot-card__actions">
        <a className="ui-btn ui-btn--secondary ao-spot-card__secondary" href={activity.plannerHref}>
          {activity.isRequestOnly ? "Plan aanvraag" : "Plan direct"}
        </a>
        {onToggleSave ? (
          <button
            type="button"
            className={`ui-btn ui-btn--secondary ao-spot-card__secondary ${isSaved ? "is-active" : ""}`.trim()}
            aria-pressed={isSaved ? "true" : "false"}
            onClick={() => onToggleSave(activity)}
          >
            {isSaved ? "Opgeslagen" : "Bewaar"}
          </button>
        ) : null}
      </div>
    </article>
  );
}
