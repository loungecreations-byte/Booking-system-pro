import type { FC } from "react";

type StickyPlannerCTAProps = {
  selectedIds: string[];
  visitDate?: string;
  audience?: string;
  count?: number;
  basePath?: string;
};

const StickyPlannerCTA: FC<StickyPlannerCTAProps> = ({
  selectedIds,
  visitDate,
  audience,
  count,
  basePath = "/plan-je-dag",
}) => {
  const hasSelection = selectedIds.length > 0;
  const params = new URLSearchParams();

  if (hasSelection) {
    params.set("activities", selectedIds.join(","));
  }
  if (visitDate) {
    params.set("visitDate", visitDate);
  }
  if (audience) {
    params.set("audience", audience);
  }
  if (typeof count === "number" && !Number.isNaN(count)) {
    params.set("count", String(count));
  }

  const href = `${basePath}?${params.toString()}`;

  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-0 z-50">
      <div
        className={`ui-motion-panel pointer-events-auto mx-auto flex w-full max-w-5xl flex-col gap-2 rounded-t-3xl border border-[color:var(--ui-color-border)] bg-[color:var(--ui-color-surface)] px-5 py-4 shadow-lg shadow-[color:rgba(15,23,42,0.10)] md:px-8 ${
          hasSelection ? "translate-y-0" : "translate-y-5 opacity-80 md:opacity-90"
        }`}
        role="region"
        aria-live="polite"
      >
        {hasSelection ? (
          <>
            <div className="flex flex-col text-sm text-[color:var(--ui-color-text-muted)] md:flex-row md:items-center md:justify-between">
              <span className="font-semibold text-[color:var(--ui-color-text)]">Toegevoegd aan je dag</span>
              <span>
                Plan nu je complete dag met {selectedIds.length} activiteit
                {selectedIds.length > 1 ? "en" : ""}.
              </span>
            </div>
            <a
              href={href}
              className="ui-btn ui-btn--primary w-full text-base"
            >
              Plan nu je complete dag
            </a>
          </>
        ) : (
          <div className="flex flex-col items-center gap-3 text-sm text-[color:var(--ui-color-text-muted)] md:flex-row md:justify-between">
            <span>Voeg activiteiten toe om direct te plannen.</span>
            <button
              type="button"
              disabled
              className="ui-btn ui-btn--secondary cursor-not-allowed opacity-60"
              aria-disabled="true"
            >
              Selecteer eerst iets leuks
            </button>
          </div>
        )}
      </div>
    </div>
  );
};

export default StickyPlannerCTA;
