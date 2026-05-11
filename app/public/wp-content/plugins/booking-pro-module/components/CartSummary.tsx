import type { FC } from "react";
import type { DayPlanItem } from "../types/DayPlanItem";

type RuntimeCapability = {
  status?: "DIRECT" | "DIRECT_LIMITED" | "REQUEST" | "UNAVAILABLE" | string;
  route_intent?: "checkout" | "quote" | "blocked" | string;
  routeIntent?: "checkout" | "quote" | "blocked" | string;
  reason_code?: string | null;
  reasonCode?: string | null;
  legacy_status?: string;
};

type RuntimeTotals = {
  authoritative_total?: number;
  estimated_total?: number;
};

type CartSummaryProps = {
  selectedItems: DayPlanItem[];
  bookingCapability?: RuntimeCapability;
  totals?: RuntimeTotals;
};

const euro = new Intl.NumberFormat("nl-NL", {
  style: "currency",
  currency: "EUR",
});

const CartSummary: FC<CartSummaryProps> = ({
  selectedItems,
  bookingCapability,
  totals,
}) => {
  const bookableItems = selectedItems.filter((item) => item.bookable);
  const routeIntent = bookingCapability?.route_intent ?? bookingCapability?.routeIntent ?? "blocked";
  const authoritativeTotal =
    typeof totals?.authoritative_total === "number" && totals.authoritative_total > 0
      ? totals.authoritative_total
      : null;
  const estimatedTotal =
    typeof totals?.estimated_total === "number" && totals.estimated_total > 0
      ? totals.estimated_total
      : bookableItems.reduce((sum, item) => sum + (item.price ?? 0), 0);
  const totalLabel = authoritativeTotal !== null ? "Totaal (definitief)" : "Totaal (indicatie)";
  const displayedTotal = authoritativeTotal ?? estimatedTotal;
  const canCheckout = routeIntent === "checkout";
  const canRequestQuote = routeIntent === "quote";
  const isBlocked = routeIntent === "blocked";

  return (
    <section className="ui-summary ui-motion-surface">
      <header className="ui-summary__header">
        <p className="text-xs font-semibold uppercase tracking-[0.3em] text-[color:var(--ui-color-text-muted)]">Winkelmand</p>
        <div className="flex items-baseline justify-between">
          <h2 className="text-3xl font-semibold text-[color:var(--ui-color-text)]">{bookableItems.length}</h2>
          <span className="text-sm text-[color:var(--ui-color-text-muted)]">boekbare onderdelen</span>
        </div>
        <p className="ui-summary__meta">Alleen activiteiten die nu te boeken zijn tellen mee.</p>
      </header>

      <div className="rounded-2xl bg-[color:var(--ui-color-surface-2)] px-4 py-3 text-[color:var(--ui-color-text)]">
        <div className="flex items-center justify-between text-sm">
          <span>{totalLabel}</span>
          <span className="text-2xl font-semibold">{euro.format(displayedTotal)}</span>
        </div>
      </div>

      {bookableItems.length ? (
        <ul className="mt-4 space-y-3">
          {bookableItems.map((item) => (
            <li key={item.id} className="ui-surface p-4 text-sm">
              <div className="flex items-center justify-between gap-3">
                <span className="font-medium text-[color:var(--ui-color-text)]">{item.title}</span>
                <span className="text-[color:var(--ui-color-text)]">{euro.format(item.price ?? 0)}</span>
              </div>
              <p className="text-xs text-[color:var(--ui-color-text-muted)]">
                {item.time} · {item.location}
              </p>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-4 rounded-2xl border border-dashed border-[color:var(--ui-color-border)] px-4 py-3 text-sm text-[color:var(--ui-color-text-muted)]">
          Selecteer activiteiten om hier je totaal te zien.
        </p>
      )}

      <div className="mt-6 space-y-3">
        <button
          type="button"
          disabled={!bookableItems.length || !canCheckout}
          className="ui-btn ui-btn--primary w-full"
        >
          Boek geselecteerde onderdelen
        </button>
        <button
          type="button"
          disabled={!bookableItems.length || !canRequestQuote}
          className="ui-btn ui-btn--secondary w-full"
        >
          Vraag offerte aan
        </button>
        <button
          type="button"
          className="ui-btn ui-btn--ghost w-full border-dashed"
        >
          Deel planning als link
        </button>
        {authoritativeTotal === null ? (
          <p className="text-xs text-[color:var(--ui-color-text-muted)]">Indicatief bedrag. Definitieve totaalprijs wordt in de runtime stap bepaald.</p>
        ) : null}
        {isBlocked ? (
          <p className="text-xs text-[color:var(--ui-color-text-muted)]">Deze planning is momenteel niet beschikbaar voor checkout of offerte.</p>
        ) : null}
      </div>
    </section>
  );
};

export default CartSummary;
