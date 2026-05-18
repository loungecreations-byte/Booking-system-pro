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
  onSelect,
  isSaved = false,
  isSelected = false,
  onToggleSave,
  variant = "default",
  ctaLabel = "Bekijk activiteit",
}: ActivityCardProps) {
  const hasImage = typeof activity.image === "string" && activity.image.trim() !== "";
  const primaryHref = activity.permalink || activity.plannerHref;
  const priceLabel = activity.priceLabel || "Prijs op aanvraag";

  return (
    <article
      className="ui-listing-card"
      data-card-variant={variant}
      data-card-state={isSaved ? "saved" : undefined}
      data-card-active={isSelected ? "true" : undefined}
      aria-current={isSelected ? "true" : undefined}
    >
      <div className="ui-listing-card__media">
        {hasImage ? (
          <img
            className="ui-listing-card__image"
            src={activity.image}
            alt={activity.title}
            loading="lazy"
            referrerPolicy="no-referrer"
          />
        ) : (
          <span className="ui-listing-card__placeholder" aria-hidden="true" />
        )}
      </div>

      <div className="ui-listing-card__overlay">
        <div className="ui-listing-card__top-left">
          {onToggleSave ? (
            <button
              type="button"
              className={`ui-listing-card__save-chip ${isSaved ? "is-active" : ""}`.trim()}
              aria-pressed={isSaved ? "true" : "false"}
              onClick={() => onToggleSave(activity)}
            >
              {isSaved ? "Opgeslagen" : "Bewaar"}
            </button>
          ) : null}
        </div>
        <div className="ui-listing-card__top-right">
          {activity.durationLabel ? (
            <span className="ui-listing-card__duration-chip">{activity.durationLabel}</span>
          ) : null}
        </div>
      </div>

      <a className="ui-listing-card__content" href={primaryHref} onFocus={() => onSelect?.(activity)}>
        <h3 className="ui-listing-card__title">{activity.title}</h3>
      </a>

      <div className="ui-listing-card__action-row">
        <div className="ui-listing-card__price">
          {activity.pricePrefix ? <span className="ui-listing-card__price-prefix">{activity.pricePrefix}</span> : null}
          <span>{priceLabel} p.p.</span>
        </div>
        <a className="ui-listing-card__cta ui-listing-card__cta--primary" href={primaryHref}>
          {activity.isRequestOnly ? "Bekijk aanvraag" : ctaLabel}
        </a>
      </div>
    </article>
  );
}
