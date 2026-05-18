import React, { useCallback, useEffect, useMemo, useState } from "react";
import { createRoot, Root } from "react-dom/client";
import ActivityFilterBar from "./components/ActivityFilterBar";
import ActivityGrid from "./components/ActivityGrid";
import {
  FILTER_PRESETS,
  fetchProducts,
  filterActivities,
  normaliseProducts,
  parseConfig,
} from "./utils";
import type { Activity, DiscoveryContext, FilterKey, ProductOverviewConfig } from "./types";
import "./styles.css";

const COMPONENT_SELECTOR = '[data-component="sbdp-activity-overview"]';
const ROOT_ATTRIBUTE = '[data-role="activity-overview-root"]';

const roots = new WeakMap<Element, Root>();

function mount(node: HTMLElement) {
  const config = parseConfig(node);
  if (!config) {
    return;
  }

  const target = node.querySelector<HTMLElement>(ROOT_ATTRIBUTE);
  if (!target) {
    return;
  }

  let root = roots.get(target);
  if (!root) {
    root = createRoot(target);
    roots.set(target, root);
  }

  root.render(<ActivityOverviewApp config={config} />);
}

function bootstrap() {
  const nodes = Array.from(document.querySelectorAll<HTMLElement>(COMPONENT_SELECTOR));
  nodes.forEach(mount);
}

if (document.readyState === "complete" || document.readyState === "interactive") {
  bootstrap();
} else {
  document.addEventListener("DOMContentLoaded", bootstrap);
}

interface ActivityOverviewAppProps {
  config: ProductOverviewConfig;
}

function ActivityOverviewApp({ config }: ActivityOverviewAppProps) {
  const isArchivePage =
    typeof document !== "undefined" && document.body.classList.contains("post-type-archive-activiteiten");
  const isSpotsPage =
    typeof document !== "undefined" && document.body.classList.contains("post-type-archive-gd_place");
  const ctaLabel = isSpotsPage ? "Bekijk plek" : "Bekijk activiteit";

  const initialFilterState = readInitialFilterState();
  const [discoveryContext, setDiscoveryContext] = useState<DiscoveryContext>(() => readDiscoveryContext());
  const [pendingContextDate, setPendingContextDate] = useState<string>(() => readDiscoveryContext().date);
  const [pendingContextParticipants, setPendingContextParticipants] = useState<number | null>(
    () => readDiscoveryContext().participants
  );
  const [activities, setActivities] = useState<Activity[]>(() => normaliseProducts(config.products, readDiscoveryContext()));
  const [selectedTags, setSelectedTags] = useState<Set<FilterKey>>(new Set());
  const [bookableOnly, setBookableOnly] = useState(false);
  const [search, setSearch] = useState(initialFilterState.search);
  const [selectedType, setSelectedType] = useState(initialFilterState.selectedType);
  const [selectedNeighborhood, setSelectedNeighborhood] = useState(initialFilterState.selectedNeighborhood);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savedIds, setSavedIds] = useState<Set<number>>(() => readSavedCards());


  useEffect(() => {
    let cancelled = false;

    async function hydrate() {
      if (!config.ajax?.url) {
        return;
      }

      setLoading(true);
      try {
        const result = await fetchProducts(config, discoveryContext);
        if (!cancelled) {
          setActivities(normaliseProducts(result, discoveryContext));
          setError(null);
        }
      } catch (fetchError) {
        if (!cancelled) {
          const message =
            fetchError instanceof Error
              ? fetchError.message
              : "Kon de activiteiten niet vernieuwen.";
          setError(message);
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    hydrate();

    return () => {
      cancelled = true;
    };
  }, [config, discoveryContext]);

  const typeOptions = useMemo(() => buildUniqueOptions(activities.map((activity) => activity.primaryTypeLabel)), [activities]);
  const neighborhoodOptions = useMemo(
    () => buildUniqueOptions(activities.map((activity) => activity.locationLabel || activity.addressLabel)),
    [activities]
  );

  const filteredActivities = useMemo(
    () =>
      filterActivityDiscovery(activities, {
        search,
        selectedType,
        selectedNeighborhood,
        selectedTags,
        bookableOnly,
      }),
    [activities, search, selectedType, selectedNeighborhood, selectedTags, bookableOnly]
  );

  const toggleTag = (key: FilterKey) => {
    setSelectedTags((current) => {
      const next = new Set(current);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  };

  const handleToggleSave = (activity: Activity) => {
    setSavedIds((current) => {
      const next = new Set(current);
      if (next.has(activity.id)) {
        next.delete(activity.id);
      } else {
        next.add(activity.id);
      }
      writeSavedCards(next);
      return next;
    });
  };

  const handleResetFilters = () => {
    setSearch("");
    setSelectedType("");
    setSelectedNeighborhood("");
    setSelectedTags(new Set());
    setBookableOnly(false);
  };

  const handleApplyContext = useCallback(() => {
    const nextContext: DiscoveryContext = {
      date: String(pendingContextDate || "").trim(),
      participants:
        typeof pendingContextParticipants === "number" &&
        Number.isFinite(pendingContextParticipants) &&
        pendingContextParticipants > 0
          ? pendingContextParticipants
          : null,
    };

    if (typeof window !== "undefined") {
      const url = new URL(window.location.href);

      syncDiscoveryContextQuery(url, nextContext);
      window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
    }

    setDiscoveryContext(nextContext);
  }, [pendingContextDate, pendingContextParticipants]);

  const plannerBrowseHref = useMemo(() => buildPlannerBrowseHref(discoveryContext), [discoveryContext]);

  return (
    <div className="ao-shell">
      <ActivityFilterBar
        filters={FILTER_PRESETS}
        selected={selectedTags}
        onToggle={toggleTag}
        bookableOnly={bookableOnly}
        onToggleBookable={() => setBookableOnly((state) => !state)}
        resultCount={filteredActivities.length}
        activeCount={selectedTags.size}
        search={search}
        selectedType={selectedType}
        selectedNeighborhood={selectedNeighborhood}
        typeOptions={typeOptions}
        neighborhoodOptions={neighborhoodOptions}
        onSearchChange={setSearch}
        onTypeChange={setSelectedType}
        onNeighborhoodChange={setSelectedNeighborhood}
        onReset={handleResetFilters}
        onSubmit={() => undefined}
        contextDate={discoveryContext.date}
        contextParticipants={discoveryContext.participants}
        pendingContextDate={pendingContextDate}
        pendingContextParticipants={pendingContextParticipants}
        onContextDateChange={setPendingContextDate}
        onContextParticipantsChange={setPendingContextParticipants}
        onApplyContext={handleApplyContext}
      />

      <div className="ao-layout">
        <main className="ao-main">
          <ActivityGrid
            activities={filteredActivities}
            loading={loading}
            error={error}
            emptyMessage="Geen activiteiten gevonden voor deze selectie."
            savedIds={savedIds}
            onToggleSave={handleToggleSave}
            variant={isArchivePage ? "archive" : "default"}
            ctaLabel={ctaLabel}
          />
        </main>
      </div>

      <section className="ao-endcap" aria-label="Verder ontdekken">
        <div className="ao-endcap__content">
          <p className="ao-endcap__eyebrow">Verder ontdekken</p>
          <h3 className="ao-endcap__title">Nog iets verder kijken?</h3>
          <p className="ao-endcap__copy">Blijf rustig bladeren of ga door naar je dagplan.</p>
        </div>
        <div className="ao-endcap__actions">
          <a className="ui-btn ui-btn--secondary" href="/spots">
            Bekijk meer activiteiten
          </a>
          <a className="ui-btn ui-btn--primary" href={plannerBrowseHref}>
            Ga verder in planner
          </a>
        </div>
      </section>
    </div>
  );
}

function readDiscoveryContext(): { date: string; participants: number | null } {
  if (typeof window === "undefined") {
    return { date: "", participants: null };
  }

  const params = new URLSearchParams(window.location.search);
  const date = String(params.get("date") || params.get("visitDate") || "").trim();
  const participantsRaw = String(params.get("participants") || params.get("count") || "").trim();
  const participants = Number(participantsRaw);

  return {
    date,
    participants: Number.isFinite(participants) && participants > 0 ? participants : null,
  };
}

function syncDiscoveryContextQuery(url: URL, discoveryContext: DiscoveryContext): void {
  const date = String(discoveryContext.date || "").trim();
  const participants =
    typeof discoveryContext.participants === "number" &&
    Number.isFinite(discoveryContext.participants) &&
    discoveryContext.participants > 0
      ? String(discoveryContext.participants)
      : "";

  if (date !== "") {
    url.searchParams.set("date", date);
    url.searchParams.set("visitDate", date);
  } else {
    url.searchParams.delete("date");
    url.searchParams.delete("visitDate");
  }

  if (participants !== "") {
    url.searchParams.set("participants", participants);
    url.searchParams.set("count", participants);
  } else {
    url.searchParams.delete("participants");
    url.searchParams.delete("count");
  }
}

function buildPlannerBrowseHref(discoveryContext: DiscoveryContext): string {
  if (typeof window === "undefined") {
    return "/plan-je-dag";
  }

  const url = new URL("/plan-je-dag", window.location.origin);
  syncDiscoveryContextQuery(url, discoveryContext);
  return `${url.pathname}${url.search}`;
}

function buildUniqueOptions(values: string[]): { value: string; label: string }[] {
  const seen = new Set<string>();

  return values
    .map((value) => value.trim())
    .filter((value) => value !== "")
    .filter((value) => {
      const key = value.toLowerCase();
      if (seen.has(key)) {
        return false;
      }
      seen.add(key);
      return true;
    })
    .sort((a, b) => a.localeCompare(b, "nl-NL"))
    .map((value) => ({ value, label: value }));
}

interface DiscoveryFilters {
  search: string;
  selectedType: string;
  selectedNeighborhood: string;
  selectedTags: Set<FilterKey>;
  bookableOnly: boolean;
}

function readInitialFilterState(): Pick<DiscoveryFilters, "search" | "selectedType" | "selectedNeighborhood"> {
  if (typeof window === "undefined") {
    return { search: "", selectedType: "", selectedNeighborhood: "" };
  }

  const params = new URLSearchParams(window.location.search);

  return {
    search: String(params.get("ddb_q") || params.get("search") || "").trim(),
    selectedType: String(params.get("ddb_type") || "").trim(),
    selectedNeighborhood: String(params.get("ddb_area") || "").trim(),
  };
}

function filterActivityDiscovery(activities: Activity[], filters: DiscoveryFilters): Activity[] {
  const searchNeedle = filters.search.trim().toLowerCase();
  const typeNeedle = filters.selectedType.trim().toLowerCase();
  const neighborhoodNeedle = filters.selectedNeighborhood.trim().toLowerCase();

  return filterActivities(activities, filters.selectedTags, filters.bookableOnly).filter((activity) => {
    if (searchNeedle !== "") {
      const haystack = [
        activity.title,
        activity.primaryTypeLabel,
        activity.locationLabel,
        activity.addressLabel,
        activity.excerpt,
        activity.tags.join(" "),
      ]
        .join(" ")
        .toLowerCase();

      if (!haystack.includes(searchNeedle)) {
        return false;
      }
    }

    if (typeNeedle !== "" && activity.primaryTypeLabel.toLowerCase() !== typeNeedle) {
      return false;
    }

    if (neighborhoodNeedle !== "" && activity.locationLabel.toLowerCase() !== neighborhoodNeedle) {
      return false;
    }

    return true;
  });
}

function readSavedCards(): Set<number> {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return new Set<number>();
  }

  try {
    const raw = window.localStorage.getItem("ddbSavedListingCards");
    if (!raw) {
      return new Set<number>();
    }

    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return new Set<number>();
    }

    return new Set(
      parsed
        .map((value) => Number(value))
        .filter((value) => Number.isFinite(value) && value > 0)
    );
  } catch (error) {
    return new Set<number>();
  }
}

function writeSavedCards(savedIds: Set<number>) {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem("ddbSavedListingCards", JSON.stringify(Array.from(savedIds)));
  } catch (error) {
    // Best-effort persistence only.
  }
}
