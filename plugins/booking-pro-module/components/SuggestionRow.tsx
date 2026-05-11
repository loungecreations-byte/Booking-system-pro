import { useMemo, type FC } from "react";
import type { DayPlanItem } from "../types/DayPlanItem";
import ComboChip from "./ComboChip";

type SuggestionRowProps = {
  availableTime: string;
  plannedItemIds: string[];
  onAdd: (item: DayPlanItem) => void;
  replacementLabel?: string | null;
  onClearReplacement?: () => void;
};

type SuggestionSeed = {
  slug: string;
  title: string;
  duration: number;
  location: string;
  price: number;
};

const euro = new Intl.NumberFormat("nl-NL", {
  style: "currency",
  currency: "EUR",
});

const suggestionSeeds: SuggestionSeed[] = [
  { slug: "floatfit", title: "Floatfit bij Sportiom", duration: 45, location: "Sportiom", price: 23 },
  { slug: "storytrail", title: "Storytrail stadswandeling", duration: 60, location: "Binnenstad", price: 19 },
  { slug: "atelier", title: "Keramiek glazing lab", duration: 50, location: "Atelier Vonk", price: 34 },
  { slug: "cabaret", title: "Cabaret in de Toonzaal", duration: 80, location: "Willem Twee", price: 29 },
  { slug: "rooftop", title: "Sunset rooftop drinks", duration: 55, location: "Current Rooftop", price: 24 },
  { slug: "streetfood", title: "Streetfood tour Tramkade", duration: 65, location: "Tramkade", price: 28 },
  { slug: "bier", title: "Bierproeverij bij Van Kollenburg", duration: 70, location: "Orthenstraat", price: 31 },
  { slug: "glow", title: "Glow SUP op de Dieze", duration: 60, location: "Diezekade", price: 37 },
];

const durationAverage = Math.round(
  suggestionSeeds.reduce((sum, seed) => sum + seed.duration, 0) / suggestionSeeds.length
);
const prices = suggestionSeeds.map((seed) => seed.price);
const minPrice = Math.min(...prices);
const maxPrice = Math.max(...prices);
const priceRangeLabel = `${euro.format(minPrice)} - ${euro.format(maxPrice)}`;

const minutesInDay = 24 * 60;

const toMinutes = (time: string): number => {
  const [hours, minutes] = time.split(":").map((value) => Number(value) || 0);
  return hours * 60 + minutes;
};

const fromMinutes = (minutes: number): string => {
  const normalized = (minutes + minutesInDay) % minutesInDay;
  const hours = Math.floor(normalized / 60);
  const mins = normalized % 60;
  return `${hours.toString().padStart(2, "0")}:${mins.toString().padStart(2, "0")}`;
};

const SuggestionRow: FC<SuggestionRowProps> = ({
  availableTime,
  plannedItemIds,
  onAdd,
  replacementLabel,
  onClearReplacement,
}) => {
  const suggestions = useMemo(() => {
    const start = toMinutes(availableTime);
    return Array.from({ length: 23 }, (_, index) => {
      const seed = suggestionSeeds[index % suggestionSeeds.length];
      const offsetMinutes = index * 15;
      const timeMinutes = (start + offsetMinutes) % minutesInDay;

      return {
        id: `suggestion-${index + 1}-${seed.slug}`,
        title: seed.title,
        time: fromMinutes(timeMinutes),
        duration: seed.duration,
        location: seed.location,
        price: seed.price,
        bookable: true,
        selected: false,
      } as DayPlanItem;
    });
  }, [availableTime]);

  return (
    <div className="ui-summary ui-motion-surface">
      <div className="flex flex-wrap items-center gap-2">
        <p className="text-base font-semibold text-[color:var(--ui-color-text)]">
          Suggesties rond {availableTime}
        </p>
        <span className="ui-badge">ca. {durationAverage} min</span>
        <span className="ui-badge">{priceRangeLabel}</span>
        <span className="text-xs text-[color:var(--ui-color-text-muted)]">AI gebruikt tags, reistijd en beschikbaarheid.</span>
      </div>

      {replacementLabel ? (
        <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-[color:var(--ui-color-border)] bg-[color:var(--ui-color-surface-2)] px-3 py-2 text-xs text-[color:var(--ui-color-text)]">
          <span>Vervang: {replacementLabel}</span>
          {onClearReplacement ? (
            <button type="button" onClick={onClearReplacement} className="font-semibold underline">
              Annuleer
            </button>
          ) : null}
        </div>
      ) : null}

      <div className="flex gap-3 overflow-x-auto pb-2 pt-1 [scrollbar-width:none]">
        {suggestions.map((suggestion) => (
          <div key={suggestion.id} className="snap-start">
            <ComboChip
              title={suggestion.title}
              time={`${suggestion.time} · ${suggestion.location}`}
              price={euro.format(suggestion.price ?? 0)}
              isSelected={plannedItemIds.includes(suggestion.id)}
              onAdd={() => onAdd({ ...suggestion, selected: false })}
              className="snap-start"
            />
          </div>
        ))}
      </div>
    </div>
  );
};

export default SuggestionRow;
