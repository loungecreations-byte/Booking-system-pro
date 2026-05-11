import type { FC } from "react";
import type { DayPlanItem } from "../types/DayPlanItem";

type DayPlanItemProps = {
  item: DayPlanItem;
  onToggleSelect: (id: string) => void;
  onRemove: (id: string) => void;
  onReplace: (id: string) => void;
  isReplacementTarget?: boolean;
};

const euro = new Intl.NumberFormat("nl-NL", {
  style: "currency",
  currency: "EUR",
});

const formatDuration = (minutes: number): string => {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  if (hours && rest) {
    return `${hours}u ${rest}m`;
  }
  if (hours) {
    return `${hours}u`;
  }
  return `${rest}m`;
};

const DayPlanItemCard: FC<DayPlanItemProps> = ({
  item,
  onToggleSelect,
  onRemove,
  onReplace,
  isReplacementTarget = false,
}) => {
  const priceLabel = item.price != null ? euro.format(item.price) : null;
  const metaLabel = [item.time, formatDuration(item.duration), item.location].filter(Boolean).join(" · ");
  const classes = [
    "ui-card ui-motion-surface ui-motion-panel gap-0 p-5",
    item.selected ? "ui-card--featured" : "",
    isReplacementTarget ? "ring-2 ring-[color:var(--ui-color-focus)] ring-offset-2 ring-offset-[color:var(--ui-color-bg)]" : "",
  ]
    .filter(Boolean)
    .join(" ");
  const primaryButtonClasses = item.selected ? "ui-chip ui-chip--selected" : "ui-chip";

  return (
    <article className={classes}>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-[color:var(--ui-color-text-muted)]">{metaLabel}</p>
          <h3 className="text-lg font-semibold text-[color:var(--ui-color-text)]">{item.title}</h3>
          {item.bookable ? (
            <span className="ui-badge ui-badge--success">
              Boekbaar
            </span>
          ) : (
            <span className="text-xs font-medium text-[color:var(--ui-color-text-muted)]">Niet direct boekbaar</span>
          )}
        </div>
        {priceLabel ? (
          <span className="text-base font-semibold text-[color:var(--ui-color-text)]">{priceLabel}</span>
        ) : null}
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => onToggleSelect(item.id)}
          aria-pressed={item.selected}
          className={primaryButtonClasses}
        >
          {item.selected ? "In mand" : "Zet in mand"}
        </button>
        <button
          type="button"
          onClick={() => onReplace(item.id)}
          className="ui-btn ui-btn--secondary"
        >
          Vervang
        </button>
        <button
          type="button"
          onClick={() => onRemove(item.id)}
          className="ui-btn ui-btn--ghost text-[color:var(--ui-color-text-muted)] hover:text-[color:var(--ui-color-danger)]"
        >
          Verwijder
        </button>
      </div>
    </article>
  );
};

export default DayPlanItemCard;
