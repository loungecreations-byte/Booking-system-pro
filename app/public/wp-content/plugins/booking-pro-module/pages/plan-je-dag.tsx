import Head from "next/head";
import type { NextPage } from "next";
import { useMemo } from "react";
import TimelineSection from "../components/TimelineSection";
import SuggestionRow from "../components/SuggestionRow";
import CartSummary from "../components/CartSummary";
import AIRecalculateButton from "../components/AIRecalculateButton";
import { useDayPlanner } from "../lib/useDayPlanner";
import mockDayPlan from "../data/mockDayPlan";
import type { DayPlanItem } from "../types/DayPlanItem";

const MORNING_LIMIT = 12 * 60;
const EVENING_START = 17 * 60;

const parseTimeToMinutes = (time: string): number => {
  const [hours, minutes] = time.split(":").map((value) => Number(value) || 0);
  return hours * 60 + minutes;
};

const dayParts: Array<{
  key: "morning" | "midday" | "evening";
  label: string;
  fallbackTime: string;
}> = [
  { key: "morning", label: "Ochtend", fallbackTime: "09:00" },
  { key: "midday", label: "Middag", fallbackTime: "13:00" },
  { key: "evening", label: "Avond", fallbackTime: "18:30" },
];

const getDayPart = (item: DayPlanItem): "morning" | "midday" | "evening" => {
  const total = parseTimeToMinutes(item.time);
  if (total < MORNING_LIMIT) {
    return "morning";
  }
  if (total < EVENING_START) {
    return "midday";
  }
  return "evening";
};

const highlightChips = ["1) Basis", "2) Blokken", "3) Boek & deel"];
const heroReasons = [
  { title: "Compact canvas", detail: "Drie dagdelen, maximaal drie keuzes per blok. Geen eindeloze lijsten." },
  { title: "Actiegericht", detail: "Tijd, prijs en boekstatus staan klaar. Klik en het staat op je tijdlijn." },
  { title: "Rust in je flow", detail: "Planner, suggesties en winkelmand naast elkaar. Geen heen-en-weer." },
];

type QueryPrefs = {
  visitDate?: string;
  count?: number;
  duration?: string;
  audience?: string;
  vibe?: string;
};

const keywordBuckets: Record<string, string[]> = {
  kidsproof: ["kids", "kind", "gezin", "familie", "families"],
  cultuur: ["museum", "art", "kunst", "kathedraal", "kathedral", "galerie", "erfgoed", "histor", "bosch"],
  shoppen: ["shop", "winkel", "vintage", "design", "markt", "shopping"],
  bourgondisch: ["lunch", "diner", "borrel", "bieren", "proeverij", "restaurant", "bollen", "koffie"],
  verrassend: ["tour", "workshop", "wandeling", "speur", "escape", "lab", "creatief", "makers", "outdoor"],
};

const dayPartAllowed: Record<string, Array<"morning" | "midday" | "evening">> = {
  ochtend: ["morning"],
  middag: ["midday"],
  avond: ["evening"],
  "hele-dag": ["morning", "midday", "evening"],
  weekend: ["morning", "midday", "evening"],
};

const parseQueryPrefs = (): QueryPrefs => {
  if (typeof window === "undefined") {
    return {};
  }
  const params = new URLSearchParams(window.location.search);
  const visitDate = params.get("visitDate") || params.get("date") || undefined;
  const countRaw = params.get("count") || params.get("participants") || "";
  const duration = params.get("duration") || undefined;
  const audience = params.get("audience") || undefined;
  const vibe = params.get("vibe") || undefined;
  const count = Number.parseInt(countRaw || "", 10);

  return {
    visitDate,
    duration,
    audience,
    vibe,
    count: Number.isFinite(count) && count > 0 ? count : undefined,
  };
};

const scoreItem = (item: DayPlanItem, prefs: QueryPrefs): number => {
  let score = 0;
  const title = item.title.toLowerCase();
  const location = item.location.toLowerCase();
  const combined = `${title} ${location}`;

  const addScoreFor = (bucketKey: string, weight: number) => {
    const keywords = keywordBuckets[bucketKey] || [];
    if (keywords.some((kw) => combined.includes(kw))) {
      score += weight;
    }
  };

  if (prefs.vibe) {
    addScoreFor(prefs.vibe.toLowerCase(), 3);
  }
  if (prefs.audience && prefs.audience.toLowerCase().includes("gezin")) {
    addScoreFor("kidsproof", 2);
  }
  if (item.bookable) {
    score += 1;
  }

  return score;
};

const buildSuggestedPlan = (catalogue: DayPlanItem[], prefs: QueryPrefs): DayPlanItem[] => {
  const allowedParts = prefs.duration && dayPartAllowed[prefs.duration]
    ? dayPartAllowed[prefs.duration]
    : ["morning", "midday", "evening"];

  const catalogueWithScores = catalogue.map((item) => ({
    item,
    part: getDayPart(item),
    score: scoreItem(item, prefs),
  }));

  const grouped: Record<string, Array<{ item: DayPlanItem; score: number }>> = {
    morning: [],
    midday: [],
    evening: [],
  };

  catalogueWithScores.forEach((entry) => {
    grouped[entry.part].push(entry);
  });

  const pickForPart = (part: "morning" | "midday" | "evening"): DayPlanItem | null => {
    if (!allowedParts.includes(part)) {
      return null;
    }
    const pool = grouped[part]
      .slice()
      .sort((a, b) => b.score - a.score || parseTimeToMinutes(a.item.time) - parseTimeToMinutes(b.item.time));

    const selected = pool.find(Boolean);
    return selected ? { ...selected.item, selected: true } : null;
  };

  const suggested: DayPlanItem[] = [];
  const morningPick = pickForPart("morning");
  const middayPick = pickForPart("midday");
  const eveningPick = pickForPart("evening");

  [morningPick, middayPick, eveningPick].forEach((pick) => {
    if (pick) {
      suggested.push(pick);
    }
  });

  if (suggested.length === 0) {
    return catalogue.slice(0, 3).map((item) => ({ ...item, selected: true }));
  }

  return suggested;
};

const PlanJeDagPage: NextPage = () => {
  const prefs = useMemo(() => parseQueryPrefs(), []);
  const suggestedPlan = useMemo(() => buildSuggestedPlan(mockDayPlan, prefs), [prefs]);
  const { dayPlan, selectedItems, addItem, toggleSelect, removeItem, replaceItem, resetPlan } =
    useDayPlanner(suggestedPlan);

  const filterBadges = useMemo(() => {
    const badges: string[] = [];
    if (prefs.visitDate) {
      badges.push(`Datum: ${prefs.visitDate}`);
    }
    if (prefs.count) {
      badges.push(`${prefs.count} personen`);
    }
    if (prefs.duration) {
      badges.push(`Duur: ${prefs.duration}`);
    }
    if (prefs.audience) {
      badges.push(`Gezelschap: ${prefs.audience}`);
    }
    if (prefs.vibe) {
      badges.push(`Sfeer: ${prefs.vibe}`);
    }
    return badges;
  }, [prefs]);

  const groupedByPart = useMemo(
    () =>
      dayParts.reduce<Record<string, DayPlanItem[]>>((acc, part) => {
        acc[part.key] = dayPlan.filter((item) => getDayPart(item) === part.key);
        return acc;
      }, {}),
    [dayPlan]
  );

  const plannedIds = useMemo(() => dayPlan.map((item) => item.id), [dayPlan]);
  const filledDayParts = useMemo(
    () => dayParts.filter((part) => (groupedByPart[part.key] ?? []).length > 0).length,
    [groupedByPart]
  );
  const selectedCount = useMemo(() => dayPlan.filter((item) => item.selected).length, [dayPlan]);

  return (
    <>
      <Head>
        <title>Jouw Reisplanner | Booking Pro Module</title>
        <meta
          name="description"
          content="Plan je dag in drie duidelijke stappen: basis invullen, blokken kiezen en direct boeken of delen."
        />
      </Head>
      <main className="ddb-app ui-scope min-h-screen bg-[radial-gradient(circle_at_top,#f4efe6_0%,var(--ui-color-bg)_45%,var(--ui-color-bg)_100%)] py-10 text-[color:var(--ui-color-text)]">
        <div className="mx-auto w-full max-w-6xl px-4 lg:px-0">
          <div className="grid gap-8">
            <section className="ui-card ui-surface--elevated overflow-hidden border-[color:color-mix(in_srgb,var(--ui-color-primary)_25%,var(--ui-color-border))] bg-[linear-gradient(135deg,#16120f_0%,#201812_45%,#332519_100%)] px-8 py-10 text-[color:var(--ui-color-primary-contrast)] shadow-xl">
              <div className="grid gap-8 lg:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)] lg:items-start">
                <div className="space-y-5">
                  <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.35em] text-white/80">
                    <span>Plan je dag</span>
                    <span className="h-2 w-2 rounded-full bg-emerald-400" aria-hidden="true" />
                    <span>Nieuwe indeling</span>
                  </div>
                  <h1 className="text-4xl font-semibold leading-tight text-white">
                    Compact dagplan zonder puzzelen
                  </h1>
                  <p className="max-w-2xl text-sm text-white/75">
                    Eerst de basis, daarna direct per dagdeel kiezen en meteen zien wat boekbaar is. Minder scrollen,
                    meer overzicht en klaarzetten voor je klant of team.
                  </p>
                  <div className="grid gap-3 sm:grid-cols-3">
                    {heroReasons.map((reason) => (
                      <div key={reason.title} className="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <p className="text-xs uppercase tracking-wide text-white/60">{reason.title}</p>
                        <p className="mt-1 text-sm text-white/80">{reason.detail}</p>
                      </div>
                    ))}
                  </div>
                  <div className="flex flex-wrap gap-2 text-xs font-semibold text-white/80">
                    {highlightChips.map((chip) => (
                      <span key={chip} className="rounded-full border border-white/10 bg-white/10 px-3 py-1">
                        {chip}
                      </span>
                    ))}
                    {filterBadges.map((chip) => (
                      <span key={chip} className="rounded-full border border-emerald-300/20 bg-emerald-400/15 px-3 py-1 text-emerald-50">
                        {chip}
                      </span>
                    ))}
                  </div>
                </div>

                <div className="ui-summary border-white/10 bg-white/5 text-white">
                  <div className="flex items-center justify-between">
                    <p className="text-xs uppercase tracking-[0.25em] text-white/60">Dagstatus</p>
                    <span className="rounded-full bg-emerald-400/20 px-3 py-1 text-[11px] font-semibold text-emerald-100">
                      {filledDayParts}/3 gevuld
                    </span>
                  </div>
                  <div className="space-y-3 text-white/90">
                    <p className="text-sm font-semibold">1. Vul basis: datum + deelnemers</p>
                    <p className="text-sm font-semibold">2. Voeg blokken per dagdeel toe</p>
                    <p className="text-sm font-semibold">3. Selecteer en boek of deel</p>
                  </div>
                  <div className="rounded-2xl bg-white/10 p-4">
                    <p className="text-xs uppercase tracking-[0.2em] text-white/60">Nu geselecteerd</p>
                    <p className="text-2xl font-semibold text-white">
                      {selectedCount} onderdeel{selectedCount === 1 ? "" : "en"}
                    </p>
                    <p className="text-xs text-white/60">Alles wat je toevoegt blijft direct in de tijdlijn staan.</p>
                  </div>
                  <div className="flex flex-col gap-2 text-sm font-semibold lg:text-base">
                    <button
                      type="button"
                      className="ui-btn ui-btn--primary"
                      onClick={() => resetPlan(suggestedPlan)}
                    >
                      Start met aanbevolen dag
                    </button>
                    <button
                      type="button"
                      className="ui-btn ui-btn--secondary border-white/20 bg-white/5 text-white hover:bg-white/10"
                    >
                      Bouw eigen dag
                    </button>
                  </div>
                </div>
              </div>
            </section>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_380px]">
              <div className="space-y-6">
                <section className="ui-summary">
                  <div className="flex flex-col gap-3 border-b border-[color:var(--ui-color-border)] pb-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <p className="text-xs font-semibold uppercase tracking-[0.3em] text-[color:var(--ui-color-text-muted)]">
                        Dagcanvas
                      </p>
                      <h2 className="text-2xl font-semibold text-[color:var(--ui-color-text)]">Jouw planning per dagdeel</h2>
                      <p className="text-sm text-[color:var(--ui-color-text-muted)]">
                        Klik of sleep om tijden en deelnemers te wijzigen. Alles blijft chronologisch en rustig.
                      </p>
                    </div>
                    <div className="ui-badge">
                      <span className="rounded-full bg-emerald-100 px-2 py-1 text-emerald-700">{dayPlan.length} blokken</span>
                      <span>•</span>
                      <span>{filledDayParts} dagdelen gevuld</span>
                    </div>
                  </div>
                  <div className="mt-4 space-y-4">
                    {dayParts.map((part) => (
                      <TimelineSection
                        key={part.key}
                        title={part.label}
                        items={groupedByPart[part.key] ?? []}
                        onToggleSelect={toggleSelect}
                        onRemove={removeItem}
                        onReplace={replaceItem}
                      />
                    ))}
                  </div>
                </section>
              </div>

              <div className="space-y-6 lg:sticky lg:top-6">
                <section className="ui-summary">
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                      <p className="text-xs uppercase tracking-[0.3em] text-[color:var(--ui-color-text-muted)]">Snelle keuzes</p>
                      <h2 className="text-2xl font-semibold text-[color:var(--ui-color-text)]">Beschikbare combinaties</h2>
                      <p className="text-sm text-[color:var(--ui-color-text-muted)]">
                        Per dagdeel maximaal drie suggesties. Je ziet meteen welke tijd past en of het boekbaar is.
                      </p>
                    </div>
                    <AIRecalculateButton onRecalculate={async () => Promise.resolve()} />
                  </div>
                  <div className="mt-5 space-y-3">
                    {dayParts.map((part) => (
                      <SuggestionRow
                        key={part.key}
                        availableTime={groupedByPart[part.key]?.[0]?.time ?? part.fallbackTime}
                        plannedItemIds={plannedIds}
                        onAdd={(item) => addItem(item)}
                        replacementLabel={null}
                      />
                    ))}
                  </div>
                </section>

                <CartSummary selectedItems={selectedItems} />
              </div>
            </div>
          </div>
        </div>
      </main>
    </>
  );
};

export default PlanJeDagPage;
