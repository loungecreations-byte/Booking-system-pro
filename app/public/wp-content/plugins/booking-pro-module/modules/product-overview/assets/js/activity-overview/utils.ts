import type {
  Activity,
  DiscoveryContext,
  FilterDefinition,
  FilterKey,
  ProductOverviewConfig,
  ServerProduct,
} from "./types";

const FILTER_KEYWORDS: Record<FilterKey, string[]> = {
  food: ["food", "eten", "drink", "borrel", "culinair", "restaurant", "proeverij"],
  culture: ["culture", "kunst", "museum", "history", "erfgoed", "galerij"],
  adventure: ["adventure", "avontuur", "outdoor", "escape", "speurtocht", "sport"],
  family: ["kind", "kids", "family", "vriendelijk", "jong", "gezin"],
};

export const FILTER_PRESETS: FilterDefinition[] = [
  {
    key: "food",
    label: "Eten & Drinken",
    description: "Culinaire tours, proeverijen en horeca",
  },
  {
    key: "culture",
    label: "Kunst & Cultuur",
    description: "Musea, ateliers en historische wandelingen",
  },
  {
    key: "adventure",
    label: "Avontuur",
    description: "Interactie en adrenaline in en rond de stad",
  },
  {
    key: "family",
    label: "Kindvriendelijk",
    description: "Activiteiten voor gezinnen en jonge ontdekkers",
  },
];

export function parseConfig(node: HTMLElement): ProductOverviewConfig | null {
  try {
    const raw = node.getAttribute("data-config");
    if (!raw) {
      return null;
    }

    const config = JSON.parse(raw);
    if (!config || typeof config !== "object") {
      return null;
    }

    return {
      componentId: typeof config.componentId === "string" ? config.componentId : "sbdp-ao-root",
      products: Array.isArray(config.products) ? (config.products as ServerProduct[]) : [],
      filters: config.filters && typeof config.filters === "object" ? config.filters : {},
      strings: config.strings && typeof config.strings === "object" ? config.strings : {},
      ajax: normalizeAjaxConfig(config.ajax),
      discovery: normalizeDiscoveryConfig(config.discovery),
    };
  } catch (error) {
    console.warn("[ActivityOverview] Failed to parse config.", error);
    return null;
  }
}

function normalizeAjaxConfig(raw: unknown): ProductOverviewConfig["ajax"] {
  if (!raw || typeof raw !== "object") {
    return undefined;
  }

  const source = raw as Record<string, unknown>;
  const url = typeof source.url === "string" ? source.url : "";

  if (url === "") {
    return undefined;
  }

  return {
    url,
    action: typeof source.action === "string" ? source.action : undefined,
    nonce: typeof source.nonce === "string" ? source.nonce : undefined,
  };
}

function normalizeDiscoveryConfig(raw: unknown): ProductOverviewConfig["discovery"] {
  if (!raw || typeof raw !== "object") {
    return undefined;
  }

  const source = raw as Record<string, unknown>;
  const restBase = typeof source.restBase === "string" ? source.restBase.trim() : "";

  if (restBase === "") {
    return undefined;
  }

  return {
    restBase,
    nonce: typeof source.nonce === "string" ? source.nonce : undefined,
  };
}

export function normaliseProducts(
  products: ServerProduct[],
  discoveryContext: DiscoveryContext = { date: "", participants: null }
): Activity[] {
  const seen = new Set<number>();

  return products
    .map((product) => {
      if (!product || typeof product !== "object") {
        return null;
      }

      const id = typeof product.id === "number" ? product.id : Number(product.id);
      if (!Number.isFinite(id) || id <= 0) {
        return null;
      }

      const title = product.title?.trim() || "Activiteit";
      const durationLabel = product.duration?.formatted || formatDuration(product.duration?.value);
      const priceInfo = formatPrice(product.price);
      const planSlug = ensurePlanSlug(product.slug, id);
      const bucketTags = deriveBucketTags(product);
      const tags = deriveTags(product, bucketTags);
      const image = typeof product.image === "string" ? product.image.trim() : "";
      const permalink = typeof product.permalink === "string" ? product.permalink : "";
      const bookingCapability = resolveBookingCapability(product);
      const routeIntent = resolveRouteIntent(product, bookingCapability);
      const isRequestOnly = bookingCapability === "request" || routeIntent === "quote";
      const isBookable = bookingCapability === "direct" || bookingCapability === "direct_limited";
      const excerpt = typeof product.excerpt === "string" ? product.excerpt.trim() : "";
      const locationLabel = resolveLocationLabel(product);
      const primaryTypeLabel = resolvePrimaryTypeLabel(product);
      const coordinates = resolveCoordinates(product);
      const priceLevelLabel = resolvePriceLevel(priceInfo.raw);
      const openingLabel = resolveOpeningLabel(product);
      const statusLabel = resolveStatusLabel(bookingCapability);
      const plannerPrefill = isPlainRecord(product.planner_prefill) ? product.planner_prefill : undefined;
      const plannerHref = buildPlannerHref(planSlug, plannerPrefill, discoveryContext);
      const metaItems = [statusLabel, primaryTypeLabel, durationLabel, locationLabel].filter((entry) => entry !== "");

      return {
        id,
        title,
        image,
        planSlug,
        plannerHref,
        permalink,
        durationLabel,
        priceLabel: priceInfo.label,
        priceValue: priceInfo.raw,
        pricePrefix: priceInfo.raw > 0 ? "Vanaf" : "",
        priceLevelLabel,
        tags,
        excerpt,
        bucketTags,
        isBookable,
        isRequestOnly,
        bookingCapability,
        routeIntent,
        statusLabel,
        plannerPrefill,
        locationLabel,
        addressLabel: locationLabel || "Den Bosch",
        primaryTypeLabel,
        metaItems,
        coordinates,
        openingLabel,
      } as Activity;
    })
    .filter((activity): activity is Activity => {
      if (!activity) {
        return false;
      }
      if (seen.has(activity.id)) {
        return false;
      }
      seen.add(activity.id);
      return true;
    })
    .sort((a, b) => {
      if (a.priceValue !== b.priceValue) {
        return a.priceValue - b.priceValue;
      }
      return a.title.localeCompare(b.title, "nl-NL");
    });
}

export function filterActivities(
  activities: Activity[],
  selectedTags: Set<FilterKey>,
  bookableOnly: boolean
): Activity[] {
  return activities.filter((activity) => {
    if (bookableOnly && !activity.isBookable) {
      return false;
    }

    if (selectedTags.size === 0) {
      return true;
    }

    return activity.bucketTags.some((tag) => selectedTags.has(tag));
  });
}

export async function fetchProducts(
  config: ProductOverviewConfig,
  discoveryContext: DiscoveryContext = { date: "", participants: null }
): Promise<ServerProduct[]> {
  if (config.discovery?.restBase) {
    return fetchDiscoveryProducts(config, discoveryContext);
  }

  if (!config.ajax?.url) {
    return [];
  }

  const params = new URLSearchParams();
  params.append("action", config.ajax.action || "bmp_fetch_products");
  if (config.ajax.nonce) {
    params.append("nonce", config.ajax.nonce);
  }

  params.append("page", "1");

  const perPage =
    typeof config.filters?.per_page === "number" && config.filters.per_page > 0
      ? Math.min(Number(config.filters.per_page), 48)
      : 32;
  params.append("per_page", String(perPage));

  if (config.filters) {
    Object.entries(config.filters).forEach(([key, value]) => {
      if (value === undefined || value === null) {
        return;
      }
      const normalized = String(value).trim();
      if (normalized !== "") {
        params.append(key, normalized);
      }
    });
  }

  const response = await fetch(config.ajax.url, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
    },
    body: params.toString(),
  });

  const payload = await response.json();
  if (!response.ok || !payload?.success) {
    throw new Error(payload?.data?.message || "Kon activiteiten niet laden.");
  }

  const data = payload.data ?? {};

  return Array.isArray(data.products) ? (data.products as ServerProduct[]) : [];
}

async function fetchDiscoveryProducts(
  config: ProductOverviewConfig,
  discoveryContext: DiscoveryContext
): Promise<ServerProduct[]> {
  const restBase = String(config.discovery?.restBase || "").replace(/\/+$/, "");
  if (restBase === "") {
    return [];
  }

  const params = new URLSearchParams();
  params.append("per_page", resolvePerPage(config));
  appendDiscoveryQueryFilters(params, discoveryContext);

  if (config.filters) {
    Object.entries(config.filters).forEach(([key, value]) => {
      if (value === undefined || value === null || key === "per_page") {
        return;
      }
      const normalized = String(value).trim();
      if (normalized !== "") {
        params.append(key, normalized);
      }
    });
  }

  const response = await fetch(`${restBase}/activities?${params.toString()}`, {
    method: "GET",
    credentials: "same-origin",
    headers: buildDiscoveryHeaders(config.discovery?.nonce),
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload?.message || "Kon discovery-activiteiten niet laden.");
  }

  const items = Array.isArray(payload?.items) ? payload.items : payload?.products;
  return Array.isArray(items) ? (items as ServerProduct[]) : [];
}

function appendDiscoveryQueryFilters(params: URLSearchParams, discoveryContext: DiscoveryContext): void {
  const date = String(discoveryContext.date || "").trim();
  const participants =
    typeof discoveryContext.participants === "number" && Number.isFinite(discoveryContext.participants) && discoveryContext.participants > 0
      ? String(discoveryContext.participants)
      : "";

  if (date !== "") {
    params.append("date", date);
  }

  if (participants !== "") {
    params.append("participants", participants);
  }

  params.append("exclude_unavailable", date !== "" && participants !== "" ? "1" : "0");
}

function resolvePerPage(config: ProductOverviewConfig): string {
  const perPage =
    typeof config.filters?.per_page === "number" && config.filters.per_page > 0
      ? Math.min(Number(config.filters.per_page), 48)
      : 32;

  return String(perPage);
}

function buildDiscoveryHeaders(nonce?: string): HeadersInit {
  const headers: HeadersInit = {
    Accept: "application/json",
  };

  if (typeof nonce === "string" && nonce.trim() !== "") {
    headers["X-WP-Nonce"] = nonce;
    headers["x-sbdp-nonce"] = nonce;
  }

  return headers;
}

function formatDuration(raw?: number): string {
  if (!Number.isFinite(raw) || !raw) {
    return "Flexibel";
  }

  if (raw >= 60) {
    const hours = raw / 60;
    if (Number.isInteger(hours)) {
      return `${hours} uur`;
    }
    return `${hours.toFixed(1)} uur`;
  }

  return `${raw} min`;
}

function formatPrice(price?: { raw?: number; formatted?: string }): { label: string; raw: number } {
  const rawValue = typeof price?.raw === "number" ? price.raw : 0;
  if (price?.formatted && price.formatted !== "") {
    return { label: price.formatted, raw: rawValue };
  }

  try {
    const formatter = new Intl.NumberFormat("nl-NL", {
      style: "currency",
      currency: "EUR",
      minimumFractionDigits: 0,
    });
    return { label: formatter.format(rawValue), raw: rawValue };
  } catch (error) {
    console.warn("[ActivityOverview] Failed to format price.", error);
  }

  return { label: `€ ${rawValue.toFixed(2)}`, raw: rawValue };
}

function resolveLocationLabel(product: ServerProduct): string {
  const directCandidates = [product.location, product.area];
  for (const candidate of directCandidates) {
    if (typeof candidate === "string" && candidate.trim() !== "") {
      return candidate.trim();
    }
  }

  if (Array.isArray(product.categories)) {
    const locationCandidate = product.categories.find((entry) => {
      if (typeof entry !== "string") {
        return false;
      }

      const normalized = entry.trim().toLowerCase();
      return normalized.includes("binnenstad") || normalized.includes("tramkade") || normalized.includes("bossche");
    });

    if (typeof locationCandidate === "string" && locationCandidate.trim() !== "") {
      return locationCandidate.trim();
    }
  }

  return "";
}

function resolvePrimaryTypeLabel(product: ServerProduct): string {
  if (typeof product.type_label === "string" && product.type_label.trim() !== "") {
    return product.type_label.trim();
  }

  if (typeof product.type === "string" && product.type.trim() !== "") {
    return product.type.trim();
  }

  if (product.type?.label && product.type.label.trim() !== "") {
    return product.type.label.trim();
  }

  if (Array.isArray(product.categories)) {
    const first = product.categories.find((entry) => typeof entry === "string" && entry.trim() !== "");
    if (typeof first === "string") {
      return first.trim();
    }
  }

  return "Activiteit";
}

function resolveCoordinates(product: ServerProduct): { lat: number | null; lng: number | null } {
  const lat = typeof product.coordinates?.lat === "number" ? product.coordinates.lat : null;
  const lng = typeof product.coordinates?.lng === "number" ? product.coordinates.lng : null;

  if (lat === null || lng === null) {
    return { lat: null, lng: null };
  }

  if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
    return { lat: null, lng: null };
  }

  return { lat, lng };
}

function resolvePriceLevel(raw: number): string {
  if (!Number.isFinite(raw) || raw <= 0) {
    return "Gratis";
  }

  if (raw < 15) {
    return "€";
  }

  if (raw < 35) {
    return "€€";
  }

  return "€€€";
}

function resolveOpeningLabel(product: ServerProduct): string {
  const summary = product.availability?.summary;
  if (typeof summary === "string" && summary.trim() !== "") {
    return summary.trim();
  }

  if (Array.isArray(summary) && summary.length > 0) {
    const first = summary.find((entry) => typeof entry === "string" && entry.trim() !== "");
    if (typeof first === "string") {
      return first.trim();
    }
  }

  return "";
}

function ensurePlanSlug(slug: string | undefined, id: number): string {
  if (slug && slug.trim() !== "") {
    return slug.trim();
  }
  return `product-${id}`;
}

function deriveBucketTags(product: ServerProduct): FilterKey[] {
  const candidates = new Set<string>();

  if (Array.isArray(product.category_slugs)) {
    product.category_slugs.forEach((slug) => {
      if (typeof slug === "string") {
        candidates.add(slug.toLowerCase());
      }
    });
  }

  if (Array.isArray(product.categories)) {
    product.categories.forEach((label) => {
      if (typeof label === "string") {
        candidates.add(label.toLowerCase());
      }
    });
  }

  if (typeof product.type_slug === "string" && product.type_slug.trim() !== "") {
    candidates.add(product.type_slug.toLowerCase());
  }

  if (typeof product.type === "object" && product.type?.slug) {
    candidates.add(product.type.slug.toLowerCase());
  }

  const matches: FilterKey[] = [];
  (Object.keys(FILTER_KEYWORDS) as FilterKey[]).forEach((key) => {
    const keywords = FILTER_KEYWORDS[key];
    const hit = keywords.some((keyword) => {
      return Array.from(candidates).some((candidate) => candidate.includes(keyword));
    });

    if (hit) {
      matches.push(key);
    }
  });

  return matches;
}

function deriveTags(product: ServerProduct, bucketTags: FilterKey[]): string[] {
  const tags = new Set<string>();

  if (typeof product.type_label === "string" && product.type_label.trim() !== "") {
    tags.add(product.type_label.trim());
  } else if (typeof product.type === "object" && product.type?.label) {
    tags.add(product.type.label);
  }

  if (Array.isArray(product.categories)) {
    product.categories.forEach((entry) => {
      if (typeof entry === "string" && entry.trim() !== "") {
        tags.add(entry.trim());
      }
    });
  }

  bucketTags.forEach((tagKey) => {
    const preset = FILTER_PRESETS.find((preset) => preset.key === tagKey);
    if (preset) {
      tags.add(preset.label);
    }
  });

  return Array.from(tags).slice(0, 3);
}

export function selectTopPicks(activities: Activity[], limit = 4): Activity[] {
  if (!Array.isArray(activities) || activities.length === 0) {
    return [];
  }

  return [...activities]
    .sort((a, b) => {
      if (b.bucketTags.length !== a.bucketTags.length) {
        return b.bucketTags.length - a.bucketTags.length;
      }
      return a.priceValue - b.priceValue;
    })
    .slice(0, limit);
}

function resolveBookingCapability(product: ServerProduct): string {
  const raw = typeof product.booking_capability === "string" ? product.booking_capability : product.bookingCapability;
  const normalized = String(raw || "").trim().toLowerCase();

  if (
    normalized === "direct" ||
    normalized === "direct_limited" ||
    normalized === "request" ||
    normalized === "unavailable"
  ) {
    return normalized;
  }

  return product.requestOnly ? "request" : "unavailable";
}

function resolveRouteIntent(product: ServerProduct, bookingCapability: string): string {
  const routeIntent = String(product.route_intent || "").trim().toLowerCase();
  if (routeIntent === "checkout" || routeIntent === "quote" || routeIntent === "blocked") {
    return routeIntent;
  }

  if (bookingCapability === "request") {
    return "quote";
  }

  if (bookingCapability === "direct" || bookingCapability === "direct_limited") {
    return "checkout";
  }

  return "blocked";
}

function resolveStatusLabel(bookingCapability: string): string {
  switch (bookingCapability) {
    case "direct":
      return "Direct boekbaar";
    case "direct_limited":
      return "Direct boekbaar na check";
    case "request":
      return "Op aanvraag";
    default:
      return "Niet beschikbaar";
  }
}

function buildPlannerHref(
  planSlug: string,
  plannerPrefill: Record<string, unknown> | undefined,
  discoveryContext: DiscoveryContext
): string {
  const url = new URL("/plan-je-dag", window.location.origin);
  url.searchParams.set("start", planSlug);

  if (plannerPrefill) {
    appendPlannerPrefill(url, plannerPrefill);
  }

  const date = String(discoveryContext.date || "").trim();
  const participants =
    typeof discoveryContext.participants === "number" && Number.isFinite(discoveryContext.participants) && discoveryContext.participants > 0
      ? discoveryContext.participants
      : null;

  if (date !== "") {
    url.searchParams.set("date", date);
    url.searchParams.set("visitDate", date);
  }

  if (participants !== null) {
    url.searchParams.set("participants", String(participants));
    url.searchParams.set("count", String(participants));
    url.searchParams.set("people", String(participants));
  }

  return `${url.pathname}${url.search}`;
}

function appendPlannerPrefill(url: URL, plannerPrefill: Record<string, unknown>): void {
  Object.entries(plannerPrefill).forEach(([key, value]) => {
    if (value === undefined || value === null || value === "") {
      return;
    }

    if (Array.isArray(value)) {
      if (value.length === 0) {
        return;
      }
      url.searchParams.set(key, value.map((entry) => String(entry)).join(","));
      return;
    }

    if (typeof value === "object") {
      return;
    }

    url.searchParams.set(key, String(value));
  });
}

function isPlainRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === "object" && !Array.isArray(value);
}
