import type { FC } from "react";
import type { DayPlanItem } from "../types/DayPlanItem";
import DayPlanItemCard from "./DayPlanItem";

type TimelineSectionProps = {
  title: string;
  items: DayPlanItem[];
  replacementTargetId?: string | null;
  onToggleSelect: (id: string) => void;
  onRemove: (id: string) => void;
  onReplace: (id: string) => void;
};

const TimelineSection: FC<TimelineSectionProps> = ({
  title,
  items,
  replacementTargetId,
  onToggleSelect,
  onRemove,
  onReplace,
}) => (
  <section className="ui-card ui-motion-surface ui-timeline space-y-4 p-6">
    <header className="flex flex-col gap-1">
      <p className="text-xs font-semibold uppercase tracking-wide text-[color:var(--ui-color-text-muted)]">
        Dagdeel
      </p>
      <h2 className="text-2xl font-semibold text-[color:var(--ui-color-text)]">{title}</h2>
      <p className="text-sm text-[color:var(--ui-color-text-muted)]">
        {items.length
          ? "Pas onderdelen aan, boek en stel je ideale flow samen."
          : "Nog niets ingepland voor dit dagdeel – voeg iets toe vanuit de suggesties."}
      </p>
    </header>

    {items.length ? (
      <div className="relative pl-6">
        <span className="ui-timeline__track pointer-events-none" aria-hidden="true" />
        <ol className="space-y-5">
          {items.map((item) => (
            <li key={item.id} className="relative pl-4">
              <span className="ui-timeline__marker top-6" aria-hidden="true" />
              <DayPlanItemCard
                item={item}
                onToggleSelect={onToggleSelect}
                onRemove={onRemove}
                onReplace={onReplace}
                isReplacementTarget={replacementTargetId === item.id}
              />
            </li>
          ))}
        </ol>
      </div>
    ) : (
      <div className="rounded-2xl border border-dashed border-[color:var(--ui-color-border)] p-6 text-sm text-[color:var(--ui-color-text-muted)]">
        Kies een suggestie hieronder om dit blok te vullen.
      </div>
    )}
  </section>
);

export default TimelineSection;
