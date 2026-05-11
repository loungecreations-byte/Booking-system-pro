import { useEffect, useMemo, useState } from "react";
import CombiDeals, { type CombiDeal } from "./CombiDeals";
import StickyPlannerCTA from "./StickyPlannerCTA";

// @ts-ignore - JS utility
import PreferenceManager from "../../../assets/js/shared/PreferenceManager";

type ProductExperienceProps = {
  productId: string;
  slug?: string;
  title: string;
  description: string;
  price: string;
  image?: string;
  tags?: string[];
  duration?: string;
  location?: string;
  combiDeals?: CombiDeal[];
  visitDate?: string;
  audience?: string;
  count?: number;
  style?: "light" | "bold";
};

type QueryParamsState = {
  visitDate?: string;
  audience?: string;
  count?: number;
  style?: string;
};

const formatVisitDate = (value?: string): string | undefined => {
  if (!value) {
    return undefined;
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return undefined;
  }
  return parsed.toLocaleDateString("nl-NL", { day: "numeric", month: "long" });
};

const buildAudienceCopy = (audience?: string, count?: number): string => {
  if (audience && count) {
    return `${audience.toLowerCase()} (${count})`;
  }
  if (audience) {
    return audience.toLowerCase();
  }
  if (count) {
    return `${count} personen`;
  }
  return "je gezelschap";
};

const ProductExperience = ({
  productId,
  slug,
  title,
  description,
  price,
  image,
  tags = [],
  duration,
  location,
  combiDeals = [],
  visitDate: visitDateProp,
  audience: audienceProp,
  count: countProp,
  style: styleProp = "light",
}: ProductExperienceProps) => {
  const [queryParams, setQueryParams] = useState<QueryParamsState>({});

  useEffect(() => {
    if (typeof window === "undefined") {
      return;
    }

    if (window.PreferenceManager) {
      const stored = window.PreferenceManager.load();
      if (stored) {
        setQueryParams({
          visitDate: stored.visitDate,
          audience: stored.audience,
          style: stored.vibe,
          count: stored.count,
        });
        return;
      }
    }

    const params = new URLSearchParams(window.location.search);
    const countValue = params.get("count");
    setQueryParams({
      visitDate: params.get("visitDate") ?? undefined,
      audience: params.get("audience") ?? undefined,
      style: params.get("style") ?? params.get("vibe") ?? undefined,
      count: countValue && !Number.isNaN(Number(countValue)) ? Number(countValue) : undefined,
    });
  }, []);

  const resolvedVisitDate = queryParams.visitDate ?? visitDateProp;
  const resolvedAudience = queryParams.audience ?? audienceProp;
  const resolvedCount = queryParams.count ?? countProp;
  const resolvedStyle = (queryParams.style ?? styleProp) === "bold" ? "bold" : "light";

  const friendlyDate = formatVisitDate(resolvedVisitDate);
  const audienceCopy = buildAudienceCopy(resolvedAudience, resolvedCount);

  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const baseSelected = selectedIds.includes(productId);

  const toggleBaseActivity = () => {
    setSelectedIds((current) =>
      current.includes(productId)
        ? current.filter((id) => id !== productId)
        : [...current, productId],
    );
  };

  const handleToggleDeal = (deal: CombiDeal) => {
    setSelectedIds((current) =>
      current.includes(deal.id)
        ? current.filter((id) => id !== deal.id)
        : [...current, deal.id],
    );
  };

  const selectedSet = useMemo(() => new Set(selectedIds), [selectedIds]);

  const autoPlanUrl = useMemo(() => {
    const preferences = {
      visitDate: resolvedVisitDate,
      audience: resolvedAudience,
      count: resolvedCount,
      startActivity: slug ?? productId,
      vibe: resolvedStyle === "bold" ? "bourgondisch" : "verrassend",
    };

    if (typeof window !== "undefined" && window.PreferenceManager) {
      window.PreferenceManager.save(preferences);
      return window.PreferenceManager.buildUrl("/plan-je-dag", preferences);
    }

    const params = new URLSearchParams();
    params.set("start", slug ?? productId);
    if (resolvedVisitDate) {
      params.set("visitDate", resolvedVisitDate);
    }
    if (resolvedAudience) {
      params.set("audience", resolvedAudience);
    }
    if (typeof resolvedCount === "number" && !Number.isNaN(resolvedCount)) {
      params.set("count", String(resolvedCount));
    }
    return `/plan-je-dag?${params.toString()}`;
  }, [productId, resolvedAudience, resolvedCount, resolvedVisitDate, resolvedStyle, slug]);

  const heroOverlay =
    resolvedStyle === "bold"
      ? "from-[color:var(--ui-color-primary)] via-[color:var(--ui-color-primary-hover)] to-transparent"
      : "from-[color:rgba(23,20,18,0.82)] via-[color:rgba(23,20,18,0.2)] to-transparent";

  const chipsAccent =
    resolvedStyle === "bold"
      ? "bg-[color:var(--ui-color-surface-2)] text-[color:var(--ui-color-primary)]"
      : "bg-[color:var(--ui-color-surface-2)] text-[color:var(--ui-color-text-muted)]";

  return (
    <section
      className="ui-scope relative mx-auto flex w-full max-w-5xl flex-col gap-10 px-4 py-10 text-[color:var(--ui-color-text)] md:px-6"
      aria-label="Productervaring planner"
    >
      <div className="grid gap-8 md:grid-cols-5 md:gap-12">
        <article className="space-y-6 md:col-span-3">
          <div className="ui-card ui-motion-surface relative h-72 w-full overflow-hidden rounded-[36px] bg-[color:var(--ui-color-surface-2)] shadow-lg">
            {image ? (
              <img
                src={image}
                alt={title}
                className="h-full w-full object-cover"
                loading="lazy"
              />
            ) : (
              <div className="flex h-full items-center justify-center bg-gradient-to-br from-[color:var(--ui-color-surface-2)] to-[color:var(--ui-color-surface-3)] text-[color:var(--ui-color-text)]">
                {title}
              </div>
            )}
            <div className={`absolute inset-0 bg-gradient-to-t ${heroOverlay}`} />
            <div className="absolute bottom-6 left-6 right-6 text-white drop-shadow">
              <p className="text-xs uppercase tracking-[0.2em] text-white/80">Den Bosch</p>
              <h1 className="mt-1 text-3xl font-semibold leading-tight">{title}</h1>
            </div>
          </div>

          <div className="space-y-3">
            <p className="text-lg font-semibold text-[color:var(--ui-color-text)]">
              Wat leuk dat je dit gekozen hebt!
            </p>
            <p className="text-base text-[color:var(--ui-color-text-muted)]">
              Voor een gezellige dag met {audienceCopy}
              {friendlyDate ? ` op ${friendlyDate}` : ""}.
            </p>
            <p className="text-sm text-[color:var(--ui-color-text-muted)]">
              Onze Bosch-bot helpt je live met slimme combinaties en routes. Voeg activiteiten toe en
              laat AI suggesties doen voor reistijd, lunch en borrelmomenten.
            </p>
          </div>

          <p className="text-base leading-relaxed text-[color:var(--ui-color-text)]">{description}</p>

          <ul className="flex flex-wrap gap-4 text-sm text-[color:var(--ui-color-text-muted)]">
            <li className="ui-badge">
              <span aria-hidden="true">⏱️</span>
              <span>{duration ?? "90 min"}</span>
            </li>
            <li className="ui-badge">
              <span aria-hidden="true">📍</span>
              <span>{location ?? "Startlocatie volgt per mail"}</span>
            </li>
            <li className="ui-badge">
              <span aria-hidden="true">💰</span>
              <span>{price} p.p.</span>
            </li>
          </ul>

          {tags.length ? (
            <div className="flex flex-wrap gap-2">
              {tags.map((tag) => (
                <span
                  key={tag}
                  className={`ui-badge ${chipsAccent}`}
                >
                  #{tag}
                </span>
              ))}
            </div>
          ) : null}

          <div className="flex flex-col gap-3 sm:flex-row">
            <button
              type="button"
              aria-pressed={baseSelected}
              onClick={toggleBaseActivity}
              className={`ui-btn ui-btn--primary w-full ${baseSelected ? "ring-2 ring-[color:var(--ui-color-primary-hover)]" : ""}`}
            >
              {baseSelected ? "Toegevoegd aan mijn dag" : "Voeg toe aan mijn dag"}
            </button>

            <a
              href={autoPlanUrl}
              className="ui-btn ui-btn--secondary w-full bg-[color:var(--ui-color-surface)]"
            >
              Of laat Bosch je dag invullen
            </a>
          </div>
        </article>

        <aside className="md:col-span-2 md:sticky md:top-10">
          <CombiDeals deals={combiDeals} selectedIds={selectedSet} onToggle={handleToggleDeal} />
        </aside>
      </div>

      {combiDeals.length === 0 ? (
        <div className="ui-summary text-sm text-[color:var(--ui-color-text-muted)]">
          Extra tips volgen binnenkort. Laat Bosch alvast een dagindeling voor je klaarzetten!
        </div>
      ) : null}

      <StickyPlannerCTA
        selectedIds={selectedIds}
        visitDate={resolvedVisitDate}
        audience={resolvedAudience}
        count={resolvedCount}
      />
    </section>
  );
};

export default ProductExperience;
