export type FilterKey = "food" | "culture" | "adventure" | "family";

export interface ServerProduct {
  id: number;
  title?: string;
  slug?: string;
  permalink?: string;
  excerpt?: string;
  location?: string;
  area?: string;
  duration?: {
    value?: number;
    formatted?: string;
  };
  price?: {
    raw?: number;
    formatted?: string;
    currency?: string;
  };
  type?: {
    label?: string;
    slug?: string;
  } | string;
  type_label?: string;
  type_slug?: string;
  image?: string;
  categories?: string[];
  category_slugs?: string[];
  is_bookable?: boolean;
  booking_capability?: string;
  bookingCapability?: string;
  route_intent?: string;
  reason_code?: string | null;
  requestOnly?: boolean;
  planner_prefill?: Record<string, unknown>;
  coordinates?: {
    lat?: number | null;
    lng?: number | null;
  };
  availability?: {
    summary?: unknown;
    dates?: unknown;
  };
}

export interface AjaxConfig {
  url: string;
  action?: string;
  nonce?: string;
}

export interface DiscoveryConfig {
  restBase: string;
  nonce?: string;
}

export interface DiscoveryContext {
  date: string;
  participants: number | null;
}

export interface ProductOverviewConfig {
  componentId: string;
  products: ServerProduct[];
  filters?: Record<string, unknown>;
  strings?: Record<string, string>;
  ajax?: AjaxConfig;
  discovery?: DiscoveryConfig;
}

export interface Activity {
  id: number;
  title: string;
  image: string;
  planSlug: string;
  plannerHref: string;
  permalink: string;
  durationLabel: string;
  priceLabel: string;
  priceValue: number;
  pricePrefix: string;
  priceLevelLabel: string;
  excerpt: string;
  tags: string[];
  bucketTags: FilterKey[];
  isBookable: boolean;
  isRequestOnly: boolean;
  bookingCapability: string;
  routeIntent: string;
  statusLabel: string;
  plannerPrefill?: Record<string, unknown>;
  locationLabel: string;
  addressLabel: string;
  primaryTypeLabel: string;
  metaItems: string[];
  coordinates: {
    lat: number | null;
    lng: number | null;
  };
  openingLabel: string;
}

export interface FilterDefinition {
  key: FilterKey;
  label: string;
  description: string;
}
