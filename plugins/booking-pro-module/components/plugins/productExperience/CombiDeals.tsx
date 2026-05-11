import type { FC } from "react";

export type CombiDeal = {
  id: string;
  title: string;
  description: string;
  price: string;
  duration?: string;
  highlight?: string;
};

type CombiDealsProps = {
  deals?: CombiDeal[];
  selectedIds: Set<string>;
  onToggle: (deal: CombiDeal) => void;
};

const CombiDeals: FC<CombiDealsProps> = ({ deals = [], selectedIds, onToggle }) => {
  if (!deals.length) {
    return null;
  }

  return (
    <section
      aria-label="Aanraders om je dag te completeren"
      className="ui-summary space-y-5 bg-[color:var(--ui-color-surface)] backdrop-blur"
    >
      <div>
        <h3 className="text-xl font-semibold text-[color:var(--ui-color-text)]">Maak je dag compleet met:</h3>
        <p className="text-sm text-[color:var(--ui-color-text-muted)]">
          Kies max. drie extra activiteiten voor een verrassend complete planning.
        </p>
      </div>

      <div className="flex flex-col gap-4">
        {deals.map((deal) => {
          const isSelected = selectedIds.has(deal.id);
          return (
            <button
              key={deal.id}
              type="button"
              aria-pressed={isSelected}
              aria-label={`Combi deal ${deal.title}`}
              onClick={() => onToggle(deal)}
              className={`ui-card ui-chip group flex w-full flex-col gap-3 px-5 py-4 text-left focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-[color:var(--ui-color-focus)] ${
                isSelected
                  ? "ui-chip--selected"
                  : "bg-[color:var(--ui-color-surface)] hover:bg-[color:var(--ui-color-surface-2)]"
              }`}
              data-selected={isSelected}
            >
              <div className="flex items-center justify-between gap-4">
                <div>
                  <p className="text-base font-semibold text-[color:var(--ui-color-text)]">{deal.title}</p>
                  {deal.duration ? (
                    <p className="text-xs text-[color:var(--ui-color-text-muted)]">⏱️ {deal.duration}</p>
                  ) : null}
                </div>
                <span className="text-sm font-semibold text-[color:var(--ui-color-text)]">{deal.price}</span>
              </div>

              <p className="text-sm text-[color:var(--ui-color-text-muted)]">{deal.description}</p>
              {deal.highlight ? (
                <p className="text-xs font-medium uppercase tracking-wide text-[color:var(--ui-color-primary)]">
                  {deal.highlight}
                </p>
              ) : null}
            </button>
          );
        })}
      </div>
    </section>
  );
};

export default CombiDeals;
