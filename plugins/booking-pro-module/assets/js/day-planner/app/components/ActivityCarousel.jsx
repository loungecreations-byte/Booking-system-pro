import React, { useEffect, useMemo, useRef, useState } from "react";
import PropTypes from "prop-types";

import { minutesToTime, timeToMinutes } from "../utils/time.js";
import { getDurationMinutes } from "../utils/products.js";
import { suggestPlannerInsertion } from "../utils/planner-engine.js";
import { formatPrice, getSlotPricePerPerson, computeSlotPricing } from "../../shared/booking.js";
import { emitPlannerEvent } from "../utils/telemetry.js";
import {
  createSearchEntry,
  createSearchIndex,
  evaluateSearchEntry,
  getProductCategoryTokens,
  prepareSearchQuery,
} from "../utils/search.js";

const ITEMS_PER_PAGE = 10;
const CATEGORY_LIMIT = 8;
const TOP_RECS_EXPERIMENT_ID = "top_recommendations_v1";
const TOP_RECS_STORAGE_KEY = "sbdp_exp_top_recommendations_v1";

const DURATION_PRESETS = [
  { value: "all", label: "Alle duur" },
  { value: "short", label: "<= 60 min" },
  { value: "medium", label: "61-120 min" },
  { value: "long", label: ">= 121 min" },
];

const PRICE_PRESETS = [
  { value: "all", label: "Alle prijzen" },
  { value: "budget", label: "EUR 0-50 p.p." },
  { value: "mid", label: "EUR 50-100 p.p." },
  { value: "premium", label: "EUR 100+ p.p." },
];

const PRICE_RULES = {
  budget: { min: 0, max: 50 },
  mid: { min: 50, max: 100 },
  premium: { min: 100, max: Number.POSITIVE_INFINITY },
};

const SORT_OPTIONS = [
  { value: "recommended", label: "Aanbevolen" },
  { value: "price-asc", label: "Prijs laag-hoog" },
  { value: "price-desc", label: "Prijs hoog-laag" },
  { value: "duration-asc", label: "Duur kort-lang" },
  { value: "duration-desc", label: "Duur lang-kort" },
  { value: "alpha", label: "A-Z" },
];

function collectDurationAvailability(products) {
  const availability = {
    short: false,
    medium: false,
    long: false,
  };

  (products || []).forEach((product) => {
    const minutes = getDurationMinutes(product);
    if (!Number.isFinite(minutes) || minutes <= 0) {
      return;
    }
    if (minutes <= 60) {
      availability.short = true;
    } else if (minutes <= 120) {
      availability.medium = true;
    } else {
      availability.long = true;
    }
  });

  return availability;
}

function collectPriceAvailability(products) {
  const availability = {
    budget: false,
    mid: false,
    premium: false,
  };

  (products || []).forEach((product) => {
    const price = getSlotPricePerPerson(product, 1, { sourceProduct: product });
    if (!Number.isFinite(price) || price <= 0) {
      return;
    }

    Object.entries(PRICE_RULES).forEach(([key, rules]) => {
      const meetsMin = Number.isFinite(rules.min) ? price >= rules.min : true;
      const meetsMax = Number.isFinite(rules.max) ? price <= rules.max : true;
      if (meetsMin && meetsMax) {
        availability[key] = true;
      }
    });
  });

  return availability;
}

function collectCategoryFilters(products) {
  const catalogue = new Map();

  (products || []).forEach((product) => {
    getProductCategoryTokens(product).forEach((token) => {
      if (!catalogue.has(token)) {
        catalogue.set(token, formatCategoryLabel(token));
      }
    });
  });

  const sorted = Array.from(catalogue.entries()).sort((a, b) =>
    a[1].localeCompare(b[1], "nl-NL", { sensitivity: "base" })
  );

  return sorted.slice(0, CATEGORY_LIMIT).map(([value, label]) => ({ value, label }));
}

function formatCategoryLabel(token) {
  if (typeof token !== "string" || token.trim() === "") {
    return "";
  }

  return token
    .split(/[-_]/u)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function getCategoryLabels(product, limit = 2) {
  return getProductCategoryTokens(product)
    .map((token) => formatCategoryLabel(token))
    .filter((label) => label !== "")
    .slice(0, limit);
}

function formatDurationLabel(minutes) {
  if (!Number.isFinite(minutes) || minutes <= 0) {
    return "N.t.b.";
  }
  if (minutes < 60) {
    return `${minutes} min`;
  }
  const hours = minutes / 60;
  if (Number.isInteger(hours)) {
    return `${hours} uur`;
  }
  return `${hours.toFixed(1)} uur`;
}

function getSortableName(product) {
  if (!product || typeof product !== "object") {
    return "";
  }
  const candidate =
    typeof product.name === "string" && product.name.trim() !== ""
      ? product.name
      : typeof product.slug === "string"
      ? product.slug
      : "";
  return candidate.trim().toLowerCase();
}

function getSortablePrice(product) {
  const perPerson = getSlotPricePerPerson(product, 1, { sourceProduct: product });
  if (Number.isFinite(perPerson) && perPerson > 0) {
    return perPerson;
  }
  const pricing = computeSlotPricing(product?.pricing || {}, 1, {
    pricePerPerson: product?.price_pp,
    sourceProduct: product,
  });
  return Number.isFinite(pricing?.perPerson) && pricing.perPerson > 0
    ? pricing.perPerson
    : null;
}

function getSortableDuration(product) {
  const minutes = getDurationMinutes(product);
  return Number.isFinite(minutes) && minutes > 0 ? minutes : null;
}

function compareMaybeNumber(aValue, bValue, direction) {
  const aValid = Number.isFinite(aValue);
  const bValid = Number.isFinite(bValue);

  if (!aValid && !bValid) {
    return 0;
  }
  if (!aValid) {
    return 1;
  }
  if (!bValid) {
    return -1;
  }
  return direction === "desc" ? bValue - aValue : aValue - bValue;
}

function resolveTopRecommendationsVariant() {
  if (typeof window === "undefined") {
    return "control";
  }

  const configuredVariant =
    window?.SBDP_DAY_PLANNER?.experiments?.[TOP_RECS_EXPERIMENT_ID] ||
    window?.SBDP_DAY_PLANNER?.experiments?.top_recommendations;

  if (configuredVariant === "control" || configuredVariant === "badge") {
    return configuredVariant;
  }

  try {
    const stored = window.localStorage.getItem(TOP_RECS_STORAGE_KEY);
    if (stored === "control" || stored === "badge") {
      return stored;
    }

    const assigned = Math.random() < 0.5 ? "control" : "badge";
    window.localStorage.setItem(TOP_RECS_STORAGE_KEY, assigned);
    return assigned;
  } catch (error) {
    return Math.random() < 0.5 ? "control" : "badge";
  }
}

export default function ActivityCarousel({
  products = [],
  allProducts = [],
  filters,
  setFilters,
  plan,
  currency,
  onConfirmAdd,
  onToggleFavorite,
  isFavorite,
  timeOptions,
  plannerConfig,
  isLoading,
  mobileFlowContent,
}) {
  const [currentPage, setCurrentPage] = useState(0);
  const searchInputRef = useRef(null);
  const topRecommendationExposureSentRef = useRef(false);
  const [searchFocused, setSearchFocused] = useState(false);
  const [sortValue, setSortValue] = useState("recommended");
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);
  const [isCoarsePointer, setIsCoarsePointer] = useState(false);
  const [topRecommendationsVariant] = useState(resolveTopRecommendationsVariant);

  useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
      return undefined;
    }

    const mediaQuery = window.matchMedia("(pointer: coarse)");
    const syncPointer = () => setIsCoarsePointer(Boolean(mediaQuery.matches));
    syncPointer();

    if (typeof mediaQuery.addEventListener === "function") {
      mediaQuery.addEventListener("change", syncPointer);
      return () => mediaQuery.removeEventListener("change", syncPointer);
    }

    mediaQuery.addListener(syncPointer);
    return () => mediaQuery.removeListener(syncPointer);
  }, []);

  const categoryOptions = useMemo(
    () => collectCategoryFilters(allProducts),
    [allProducts]
  );
  const durationAvailability = useMemo(
    () => collectDurationAvailability(allProducts),
    [allProducts]
  );
  const priceAvailability = useMemo(
    () => collectPriceAvailability(allProducts),
    [allProducts]
  );
  const durationOptions = useMemo(() => {
    const available = DURATION_PRESETS.filter((option) => option.value === "all" || durationAvailability[option.value]);
    if (!available.some((option) => option.value === "all")) {
      available.unshift(DURATION_PRESETS[0]);
    }
    return available;
  }, [durationAvailability]);

  const priceOptions = useMemo(() => {
    const available = PRICE_PRESETS.filter((option) => option.value === "all" || priceAvailability[option.value]);
    if (!available.some((option) => option.value === "all")) {
      available.unshift(PRICE_PRESETS[0]);
    }
    return available;
  }, [priceAvailability]);
  const hasPriceFilters = priceOptions.length > 1;
  const searchQuery = useMemo(
    () => prepareSearchQuery(filters.search),
    [filters.search]
  );
  const searchIndex = useMemo(
    () => createSearchIndex(allProducts),
    [allProducts]
  );
  const searchSuggestions = useMemo(() => {
    if (searchQuery.tokens.length === 0) {
      return [];
    }

    return searchIndex
      .map((entry) => {
        const evaluation = evaluateSearchEntry(entry, searchQuery);
        if (!evaluation.matches) {
          return null;
        }
        return {
          product: entry.product,
          score: evaluation.score,
        };
      })
      .filter(Boolean)
      .sort((a, b) => {
        if (b.score !== a.score) {
          return b.score - a.score;
        }
        const nameA = (a.product?.name || a.product?.slug || "").toLowerCase();
        const nameB = (b.product?.name || b.product?.slug || "").toLowerCase();
        return nameA.localeCompare(nameB, "nl-NL");
      })
      .slice(0, 5);
  }, [searchIndex, searchQuery]);
  const sortedProducts = useMemo(() => {
    if (!products?.length) {
      return [];
    }

    if (sortValue === "recommended" && searchQuery.tokens.length === 0) {
      return products;
    }

    const prepared = products.map((product, index) => ({
      product,
      index,
      name: getSortableName(product),
      price: getSortablePrice(product),
      duration: getSortableDuration(product),
    }));

    const compareName = (left, right) =>
      left.name.localeCompare(right.name, "nl-NL", { sensitivity: "base" });

    if (sortValue === "recommended") {
      const scored = prepared.map((entry) => ({
        ...entry,
        score: evaluateSearchEntry(createSearchEntry(entry.product), searchQuery).score,
      }));

      return scored
        .sort((left, right) => {
          if (right.score !== left.score) {
            return right.score - left.score;
          }
          const nameCompare = compareName(left, right);
          return nameCompare !== 0 ? nameCompare : left.index - right.index;
        })
        .map((entry) => entry.product);
    }

    const compareBase = (left, right) => {
      const nameCompare = compareName(left, right);
      return nameCompare !== 0 ? nameCompare : left.index - right.index;
    };

    const sorted = [...prepared].sort((left, right) => {
      switch (sortValue) {
        case "price-asc":
          return compareMaybeNumber(left.price, right.price, "asc") || compareBase(left, right);
        case "price-desc":
          return compareMaybeNumber(left.price, right.price, "desc") || compareBase(left, right);
        case "duration-asc":
          return compareMaybeNumber(left.duration, right.duration, "asc") || compareBase(left, right);
        case "duration-desc":
          return compareMaybeNumber(left.duration, right.duration, "desc") || compareBase(left, right);
        case "alpha":
          return compareBase(left, right);
        default:
          return compareBase(left, right);
      }
    });

    return sorted.map((entry) => entry.product);
  }, [products, searchQuery, sortValue]);
  const showSearchSuggestions =
    searchFocused && searchQuery.tokens.length > 0 && searchSuggestions.length > 0;
  const searchInputId = "sbdp-activity-search";
  const searchSuggestionsId = `${searchInputId}-suggestions`;
  const resultLabel = useMemo(() => {
    if (isLoading) {
      return "Activiteiten worden geladen...";
    }
    if (products.length === 0) {
      if (allProducts.length === 0) {
        return "Nog geen activiteiten aangemaakt.";
      }
      return "Geen activiteiten gevonden voor deze filters.";
    }
    if (products.length === allProducts.length) {
      return `${products.length} activiteiten beschikbaar`;
    }
    return `${products.length} van ${allProducts.length} activiteiten zichtbaar`;
  }, [isLoading, products.length, allProducts.length]);

  useEffect(() => {
    if (filters.duration !== "all" && !durationAvailability[filters.duration]) {
      setFilters({ duration: "all" });
    }
  }, [filters.duration, durationAvailability, setFilters]);

  useEffect(() => {
    if (filters.price !== "all" && !priceAvailability[filters.price]) {
      setFilters({ price: "all" });
    }
  }, [filters.price, priceAvailability, setFilters]);

  useEffect(() => {
    if (currentPage !== 0) {
      setCurrentPage(0);
    }
  }, [
    sortValue,
    filters.search,
    filters.duration,
    filters.category,
    filters.price,
    filters.environment,
  ]);

  const handleSearchChange = (event) => {
    const value = event.target.value;
    setFilters({ search: value });
    emitPlannerEvent("sbdp:planner/filter-change", {
      filter_type: "search",
      filter_value: value,
      result_count: products.length,
    });
  };

  const handleSuggestionSelect = (product) => {
    const nameLabel =
      typeof product?.name === "string" && product.name.trim() !== ""
        ? product.name
        : typeof product?.slug === "string" && product.slug.trim() !== ""
        ? product.slug
        : "";

    if (nameLabel === "") {
      return;
    }

    if (nameLabel !== filters.search) {
      setFilters({ search: nameLabel });
    }

    emitPlannerEvent("sbdp:planner/filter-change", {
      filter_type: "search_suggestion",
      filter_value: nameLabel,
      product_id: product?.id ?? null,
    });

    setSearchFocused(false);
    if (searchInputRef.current) {
      searchInputRef.current.blur();
    }
  };

  const handleSortChange = (event) => {
    const value = event.target.value;
    setSortValue(value);
    emitPlannerEvent("sbdp:planner/filter-change", {
      filter_type: "sort",
      filter_value: value,
      result_count: products.length,
    });
  };

  const handleDurationChange = (event) => {
    const value = event.target.value;
    setFilters({ duration: value });
    emitPlannerEvent("sbdp:planner/filter-change", {
      filter_type: "duration",
      filter_value: value,
      result_count: products.length,
    });
  };

  const handleCategoryChange = (event) => {
    const value = event.target.value;
    setFilters({ category: value });
    emitPlannerEvent("sbdp:planner/filter-change", {
      filter_type: "category",
      filter_value: value,
      result_count: products.length,
    });
  };

  const handlePriceChange = (event) => {
    const value = event.target.value;
    setFilters({ price: value });
    emitPlannerEvent("sbdp:planner/filter-change", {
      filter_type: "price",
      filter_value: value,
      result_count: products.length,
    });
  };

  const totalPages = useMemo(() => {
    if (!sortedProducts.length) {
      return 0;
    }

    return Math.ceil(sortedProducts.length / ITEMS_PER_PAGE);
  }, [sortedProducts.length]);

  useEffect(() => {
    if (totalPages === 0) {
      if (currentPage !== 0) {
        setCurrentPage(0);
      }
      return;
    }

    if (currentPage > totalPages - 1) {
      setCurrentPage(totalPages - 1);
    }
  }, [currentPage, totalPages]);

  const visibleProducts = useMemo(() => {
    if (!products?.length) {
      return [];
    }

    const safePage = Math.min(currentPage, Math.max(0, totalPages - 1));
    const start = safePage * ITEMS_PER_PAGE;
    return sortedProducts.slice(start, start + ITEMS_PER_PAGE);
  }, [sortedProducts, currentPage, totalPages]);

  const handleNext = () => {
    if (totalPages <= 1) {
      return;
    }

    emitPlannerEvent("sbdp:planner/action", {
      action: "results_pagination",
      status: "next",
      page: currentPage + 2,
    });
    setCurrentPage((page) => Math.min(totalPages - 1, page + 1));
  };

  const handlePrev = () => {
    if (totalPages <= 1) {
      return;
    }

    emitPlannerEvent("sbdp:planner/action", {
      action: "results_pagination",
      status: "prev",
      page: Math.max(1, currentPage),
    });
    setCurrentPage((page) => Math.max(0, page - 1));
  };

  const pageLabel = totalPages === 0 ? 0 : currentPage + 1;

  const isEmptyState = products.length === 0 && allProducts.length === 0;

  const topRecommendations = useMemo(() => {
    const source = sortedProducts.length > 0 ? sortedProducts : products;
    return source.slice(0, 3);
  }, [sortedProducts, products]);

  const addProductToPlanner = (product, source = "add_activity_from_card") => {
    const availableDays = Array.isArray(plan?.days) ? plan.days : [];
    let fallbackDayIndex = 0;
    if (availableDays.length > 1 && Array.isArray(plan?.items)) {
      const counts = availableDays.map((_, idx) =>
        plan.items.filter((item) => item.dayIndex === idx).length
      );
      const minCount = Math.min(...counts);
      fallbackDayIndex = Math.max(0, counts.indexOf(minCount));
    }
    const target = resolvePlannerTarget(
      product,
      fallbackDayIndex,
      timeOptions?.[0]?.value || "10:00"
    );
    onConfirmAdd(product.id, {
      dayIndex: target.dayIndex,
      startTime: target.startTime,
    });
    emitPlannerEvent("sbdp:planner/action", {
      action: source,
      status: "button_click",
      product_id: product.id,
      day_index: target.dayIndex,
      start_time: target.startTime,
      suggestion_reason: target.reason,
      experiment_id: TOP_RECS_EXPERIMENT_ID,
      experiment_variant: topRecommendationsVariant,
    });
  };

  const resolvePlannerTarget = (product, fallbackDayIndex = 0, fallbackStartTime = "10:00") => {
    const suggestion = suggestPlannerInsertion({
      product,
      plan,
      config: plannerConfig,
    });

    if (suggestion) {
      return {
        dayIndex: suggestion.dayIndex,
        startTime: suggestion.startTime,
        reason: suggestion.reason,
      };
    }

    return {
      dayIndex: fallbackDayIndex,
      startTime: fallbackStartTime,
      reason: "fallback",
    };
  };

  useEffect(() => {
    if (topRecommendationExposureSentRef.current || topRecommendations.length === 0) {
      return;
    }

    topRecommendationExposureSentRef.current = true;
    emitPlannerEvent("sbdp:planner/experiment-exposure", {
      experiment_id: TOP_RECS_EXPERIMENT_ID,
      experiment_variant: topRecommendationsVariant,
      placement: "top_recommendations",
      recommendation_count: topRecommendations.length,
    });
  }, [topRecommendations.length, topRecommendationsVariant]);
  
  const activeFilters = useMemo(() => {
    const entries = [];

    const searchValue = typeof filters.search === "string" ? filters.search.trim() : "";
    if (searchValue !== "") {
      entries.push({
        type: "search",
        label: "Zoek",
        value: searchValue,
        onClear: () => {
          setFilters({ search: "" });
          emitPlannerEvent("sbdp:planner/filter-change", {
            filter_type: "search",
            filter_value: "all",
            action: "clear_single",
          });
        },
      });
    }

    if (filters.duration && filters.duration !== "all") {
      const match = DURATION_PRESETS.find((preset) => preset.value === filters.duration);
      entries.push({
        type: "duration",
        label: "Duur",
        value: match ? match.label : filters.duration,
        onClear: () => {
          setFilters({ duration: "all" });
          emitPlannerEvent("sbdp:planner/filter-change", {
            filter_type: "duration",
            filter_value: "all",
            action: "clear_single",
          });
        },
      });
    }

    if (filters.category && filters.category !== "all") {
      const normalized = categoryOptions.find((option) => option.value === filters.category);
      entries.push({
        type: "category",
        label: "Categorie",
        value: normalized ? normalized.label : filters.category,
        onClear: () => {
          setFilters({ category: "all" });
          emitPlannerEvent("sbdp:planner/filter-change", {
            filter_type: "category",
            filter_value: "all",
            action: "clear_single",
          });
        },
      });
    }

    if (filters.price && filters.price !== "all") {
      const match = PRICE_PRESETS.find((preset) => preset.value === filters.price);
      entries.push({
        type: "price",
        label: "Prijs",
        value: match ? match.label : filters.price,
        onClear: () => {
          setFilters({ price: "all" });
          emitPlannerEvent("sbdp:planner/filter-change", {
            filter_type: "price",
            filter_value: "all",
            action: "clear_single",
          });
        },
      });
    }

    return entries;
  }, [filters, categoryOptions, setFilters]);

  const hasActiveFilters = activeFilters.length > 0;

  const handleClearAll = () => {
    setFilters({
      search: "",
      duration: "all",
      category: "all",
      price: "all",
    });
    emitPlannerEvent("sbdp:planner/filter-change", {
      filter_type: "reset_all",
      filter_value: "all",
      result_count: allProducts.length,
    });
  };

  const renderFilterControls = (className = "") => (
    <>
      <label className={`sbdp-filter-select ${className}`.trim()}>
        <span className="sbdp-visually-hidden">Sorteer op</span>
        <select value={sortValue} onChange={handleSortChange}>
          {SORT_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <label className={`sbdp-filter-select ${className}`.trim()}>
        <span className="sbdp-visually-hidden">Filter op duur</span>
        <select value={filters.duration} onChange={handleDurationChange}>
          {durationOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      {categoryOptions.length > 0 ? (
        <label className={`sbdp-filter-select ${className}`.trim()}>
          <span className="sbdp-visually-hidden">Filter op categorie</span>
          <select value={filters.category} onChange={handleCategoryChange}>
            <option value="all">Alle categorieën</option>
            {categoryOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
      ) : null}
      {hasPriceFilters ? (
        <label className={`sbdp-filter-select ${className}`.trim()}>
          <span className="sbdp-visually-hidden">Filter op prijs</span>
          <select value={filters.price} onChange={handlePriceChange}>
            {priceOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
      ) : null}
    </>
  );

  return (
    <section className="sbdp-activity-carousel">
      <div className="sbdp-activity-ribbon">
        <div className="sbdp-activity-ribbon__search">
          <span aria-hidden="true">
            <SearchIcon />
          </span>
          <label className="sbdp-visually-hidden" htmlFor={searchInputId}>
            Zoek naar activiteiten
          </label>
          <input
            id={searchInputId}
            ref={searchInputRef}
            type="search"
            placeholder="Zoek op naam of categorie"
            value={filters.search}
            onChange={handleSearchChange}
            onFocus={() => setSearchFocused(true)}
            onBlur={() => setSearchFocused(false)}
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                setSearchFocused(false);
                event.currentTarget.blur();
              }
            }}
            aria-autocomplete="list"
            aria-controls={searchSuggestionsId}
            aria-expanded={showSearchSuggestions ? "true" : "false"}
          />
          <button
            type="button"
            className="sbdp-mobile-filter-toggle"
            onClick={() => {
              setMobileFiltersOpen(true);
              emitPlannerEvent("sbdp:planner/filter-sheet", {
                state: "open",
                source: "mobile",
              });
            }}
          >
            Filters
          </button>
          {showSearchSuggestions ? (
            <ul
              id={searchSuggestionsId}
              className="sbdp-search-suggestions"
              role="listbox"
              aria-label="Zoeksuggesties"
            >
              {searchSuggestions.map((item, index) => {
                const product = item.product;
                const key = product?.id ?? product?.slug ?? `suggestion-${index}`;
                const displayName = product?.name || product?.slug || "Onbekend product";
                const categories = getCategoryLabels(product, 3);

                return (
                  <li key={key}>
                    <button
                      type="button"
                      className="sbdp-search-suggestion"
                      onMouseDown={(event) => {
                        event.preventDefault();
                        handleSuggestionSelect(product);
                      }}
                    >
                      <span className="sbdp-search-suggestion__title">{displayName}</span>
                      {categories.length > 0 ? (
                        <span className="sbdp-search-suggestion__badges">
                          {categories.map((label) => (
                            <span key={label} className="sbdp-search-suggestion__badge">
                              {label}
                            </span>
                          ))}
                        </span>
                      ) : null}
                    </button>
                  </li>
                );
              })}
            </ul>
          ) : null}
        </div>
        <div className="sbdp-activity-ribbon__filters sbdp-activity-ribbon__filters--desktop">
          {renderFilterControls()}
        </div>
        {hasActiveFilters ? (
          <div className="sbdp-activity-ribbon__chips" role="status" aria-live="polite">
            {activeFilters.map((entry) => (
              <button key={`${entry.type}-${entry.value}`} type="button" className="sbdp-filter-chip" onClick={entry.onClear}>
                <span className="sbdp-filter-chip__text">
                  {entry.label}: {entry.value}
                </span>
                <span className="sbdp-filter-chip__icon" aria-hidden="true">
                  <CloseIcon />
                </span>
                <span className="sbdp-visually-hidden">Verwijder filter {entry.label}</span>
              </button>
            ))}
            <button type="button" className="sbdp-filter-chip sbdp-filter-chip--reset" onClick={handleClearAll} aria-label="Wis alle filters">
              Wis alles
            </button>
          </div>
        ) : null}
      </div>

      <div className={`sbdp-mobile-filter-sheet ${mobileFiltersOpen ? "is-open" : ""}`.trim()}>
        <button
          type="button"
          className="sbdp-mobile-filter-sheet__backdrop"
          aria-label="Sluit filters"
          onClick={() => {
            setMobileFiltersOpen(false);
            emitPlannerEvent("sbdp:planner/filter-sheet", {
              state: "close",
              source: "mobile_backdrop",
            });
          }}
        />
        <div className="sbdp-mobile-filter-sheet__panel" role="dialog" aria-modal="true" aria-label="Filters">
          <header className="sbdp-mobile-filter-sheet__header">
            <h4>Filters</h4>
            <button
              type="button"
              onClick={() => {
                setMobileFiltersOpen(false);
                emitPlannerEvent("sbdp:planner/filter-sheet", {
                  state: "close",
                  source: "mobile_button",
                });
              }}
            >
              Gereed
            </button>
          </header>
          <div className="sbdp-activity-ribbon__filters sbdp-activity-ribbon__filters--sheet">
            {renderFilterControls("sbdp-filter-select--sheet")}
          </div>
        </div>
      </div>

      {mobileFlowContent ? (
        <div className="sbdp-activity-carousel__mobile-flow">
          {mobileFlowContent}
        </div>
      ) : null}

      {isEmptyState ? (
        <div className="sbdp-activity-carousel__empty">
          {isLoading ? (
            <p>Activiteiten worden geladen...</p>
          ) : (
            <p>
              Er zijn nog geen boekbare activiteiten geconfigureerd. Controleer of producten als
              boekbaar zijn gemarkeerd of voer de planning seeder{" "}
              <code>wp eval &quot;do_action(&#39;sbdp_seed_demo_data&#39;);&quot;</code> uit voor
              voorbeelddata.
            </p>
          )}
        </div>
      ) : products.length === 0 ? (
        <div className="sbdp-activity-carousel__empty">
          <div className="sbdp-empty-state">
            <span className="sbdp-empty-state__icon" aria-hidden="true">🔍</span>
            <h4>Geen activiteiten gevonden</h4>
            <p>Pas je filters aan om meer opties te zien.</p>
            <button
              type="button"
              className="ui-btn ui-btn--secondary"
              onClick={() => setFilters({
                search: '',
                category: 'all',
                duration: 'all',
                price: 'all',
                environment: 'both',
              })}
            >
              Filters wissen
            </button>
          </div>
          {topRecommendations.length > 0 ? (
            <div className="sbdp-activity-carousel__recommendations">
              <div className="sbdp-activity-carousel__recommendations-head">
                <strong>Aanbevolen om je dag te vullen</strong>
                <span>Uit je volledige catalogus</span>
              </div>
              <div className="sbdp-activity-carousel__recommendations-grid">
                {topRecommendations.map((product) => {
                  const pricePerPerson = formatPrice(
                    getSlotPricePerPerson(product, 1, { sourceProduct: product }),
                    currency
                  );
                  return (
                    <article key={`rec-${product.id}`} className="sbdp-top-recommendation ui-panel ui-panel--soft">
                      <div className="sbdp-top-recommendation__body">
                        <span className="sbdp-top-recommendation__eyebrow">
                          {product.isArrangement || product.kind === "arrangement" ? "Combi arrangement" : "Activiteit"}
                        </span>
                        <strong>{product.name}</strong>
                        <span>{pricePerPerson} p.p.</span>
                      </div>
                      <button
                        type="button"
                        className="ui-btn ui-btn--secondary"
                        onClick={() => addProductToPlanner(product, "add_activity_from_recommendation")}
                      >
                        Voeg toe
                      </button>
                    </article>
                  );
                })}
              </div>
            </div>
          ) : null}
        </div>
      ) : (
        <>
          {topRecommendations.length > 0 && (visibleProducts.length < ITEMS_PER_PAGE || hasActiveFilters) ? (
            <div className="sbdp-activity-carousel__recommendations">
              <div className="sbdp-activity-carousel__recommendations-head">
                <strong>Aanbevolen voor jouw dagopbouw</strong>
                <span>Geselecteerd uit de volledige catalogus</span>
              </div>
              <div className="sbdp-activity-carousel__recommendations-grid">
                {topRecommendations.map((product) => {
                  const addedCount = Array.isArray(plan?.items)
                    ? plan.items.filter((item) => Number(item.productId) === Number(product.id)).length
                    : 0;
                  const pricePerPerson = formatPrice(
                    getSlotPricePerPerson(product, 1, { sourceProduct: product }),
                    currency
                  );
                  return (
                    <article key={`rec-${product.id}`} className="sbdp-top-recommendation ui-panel ui-panel--soft">
                      <div className="sbdp-top-recommendation__body">
                        <span className="sbdp-top-recommendation__eyebrow">
                          {product.isArrangement || product.kind === "arrangement" ? "Combi arrangement" : "Activiteit"}
                        </span>
                        <strong>{product.name}</strong>
                        <span>
                          {pricePerPerson} p.p.
                          {addedCount > 0 ? ` · ${addedCount > 1 ? `${addedCount}x in planning` : "al in planning"}` : ""}
                        </span>
                      </div>
                      <button
                        type="button"
                        className="ui-btn ui-btn--secondary"
                        onClick={() => addProductToPlanner(product, "add_activity_from_recommendation")}
                      >
                        Voeg toe
                      </button>
                    </article>
                  );
                })}
              </div>
            </div>
          ) : null}
          <div className="sbdp-activity-carousel__viewport">
            <div className="sbdp-activity-carousel__grid" role="list">
              {visibleProducts.map((product, index) => {
                const favorite = typeof isFavorite === "function" ? isFavorite(product.id) : false;
                const primaryCategory = getCategoryLabels(product, 1)[0] ?? "n.t.b.";
                const kindLabel = product.isArrangement || product.kind === "arrangement" ? "Arrangement" : "Los item";
                const peopleMin = Number(product.people?.min ?? product.people?.minimum ?? 0);
                const peopleMax = Number(product.people?.max ?? product.people?.maximum ?? 0);
                const participantRange =
                  product.people?.enabled && peopleMin > 0 && peopleMax > 0
                    ? `${peopleMin}-${peopleMax}`
                    : "n.t.b.";
                const durationLabel = formatDurationLabel(getDurationMinutes(product));
                const slotPrice = computeSlotPricing(product?.pricing || {}, 1, {
                  pricePerPerson: product?.price_pp,
                  sourceProduct: product,
                });
                const pricePerPerson = formatPrice(
                  slotPrice.perPerson || getSlotPricePerPerson(product, 1),
                  currency
                );
                const addedCount = Array.isArray(plan?.items)
                  ? plan.items.filter((item) => Number(item.productId) === Number(product.id)).length
                  : 0;
                const cardState = addedCount > 0 ? "added" : undefined;
                const productUrl = product.permalink?.trim() || "";

                const openProductDetails = () => {
                  if (!productUrl) {
                    return;
                  }
                  emitPlannerEvent("sbdp:planner/action", {
                    action: "open_product_details",
                    status: "card_open",
                    product_id: product.id,
                    destination: productUrl,
                  });
                  window.location.assign(productUrl);
                };

                return (
                  <article
                    key={product.id}
                    className="ui-listing-card"
                    data-card-state={cardState}
                    data-card-clickable={productUrl ? "true" : "false"}
                    draggable={!isCoarsePointer}
                    role="listitem"
                    tabIndex={productUrl ? 0 : -1}
                    onDragStart={(event) => handleDragStart(event, product)}
                    onClick={(event) => {
                      if (!productUrl || isInteractiveTarget(event.target)) {
                        return;
                      }
                      openProductDetails();
                    }}
                    onKeyDown={(event) => {
                      if (!productUrl || (event.key !== "Enter" && event.key !== " ")) {
                        return;
                      }
                      if (isInteractiveTarget(event.target)) {
                        return;
                      }
                      event.preventDefault();
                      openProductDetails();
                    }}
                  >
                    <div className="ui-listing-card__media">
                      {product.image && product.image.trim() !== '' ? (
                        <img
                          className="ui-listing-card__image"
                          src={product.image}
                          alt={product.name || ''}
                          loading="lazy"
                          referrerPolicy="no-referrer"
                        />
                      ) : (
                        <span className="ui-listing-card__placeholder" aria-hidden="true" />
                      )}
                    </div>
                    <div className="ui-listing-card__overlay">
                      <div className="ui-listing-card__header">
                        <div className="ui-listing-card__header-main">
                          <p className="ui-listing-card__eyebrow">{kindLabel}</p>
                          {addedCount > 0 ? (
                            <span className="ui-listing-card__state">
                              {addedCount > 1 ? `${addedCount}x in planning` : "Al in planning"}
                            </span>
                          ) : null}
                        </div>
                        <button
                          type="button"
                          className={`ui-listing-card__icon-btn ui-listing-card__icon-btn--favorite ${favorite ? "is-active" : ""}`.trim()}
                          onClick={() => {
                            onToggleFavorite(product);
                            emitPlannerEvent("sbdp:planner/action", {
                              action: favorite ? "favorite_remove" : "favorite_add",
                              status: "button_click",
                              product_id: product.id,
                            });
                          }}
                          aria-label={favorite ? "Verwijder favoriet" : "Opslaan als favoriet"}
                          aria-pressed={favorite ? "true" : "false"}
                          data-ui-card-save
                        >
                          <HeartIcon />
                        </button>
                      </div>
                      <h4 className="ui-listing-card__title">{product.name}</h4>
                      <div className="ui-listing-card__price">
                        <span className="ui-listing-card__price-prefix">Vanaf</span>
                        <span>{pricePerPerson} p.p.</span>
                      </div>
                      <div className="ui-listing-card__meta">
                        <span className="ui-listing-card__meta-item">{primaryCategory}</span>
                        <span className="ui-listing-card__meta-item">{durationLabel}</span>
                        {Number.isFinite(product.segment_count) && product.segment_count > 0 ? (
                          <span className="ui-listing-card__meta-item">{product.segment_count} segmenten</span>
                        ) : null}
                        {product.arrangement_type ? (
                          <span className="ui-listing-card__meta-item">
                            {product.arrangement_type === "fixed" ? "Vast" : product.arrangement_type === "dynamic" ? "Flexibel" : "Maatwerk"}
                          </span>
                        ) : null}
                      </div>
                      <footer className="ui-listing-card__actions">
                        <a
                          className="ui-listing-card__cta ui-listing-card__cta--secondary"
                          href={productUrl || "#"}
                          onClick={(event) => {
                            if (!productUrl) {
                              event.preventDefault();
                            }
                          }}
                        >
                          Bekijk
                        </a>
                        <button
                          type="button"
                          className={`ui-listing-card__cta ui-listing-card__cta--primary ${addedCount > 0 ? "is-added" : ""}`.trim()}
                          disabled={product.can_add_to_cart === false}
                          onClick={() => {
                            if (product.can_add_to_cart === false) {
                              return;
                            }
                            addProductToPlanner(product);
                          }}
                        >
                          {product.can_add_to_cart === false ? "Preview" : addedCount > 0 ? "Nogmaals toevoegen" : "Voeg toe"}
                        </button>
                      </footer>
                    </div>
                  </article>
                );
              })}
            </div>
          </div>
          <div className="sbdp-activity-carousel__pager">
            <button
              type="button"
              className="sbdp-link-button ui-btn ui-btn--ghost ui-btn--inline"
              onClick={handlePrev}
              disabled={currentPage === 0}
            >
              Vorige activiteiten
            </button>
            <span className="sbdp-activity-carousel__pagination">
              Pagina {pageLabel} van {Math.max(1, totalPages)}
            </span>
            <button
              type="button"
              className="sbdp-link-button ui-btn ui-btn--ghost ui-btn--inline"
              onClick={handleNext}
              disabled={currentPage >= totalPages - 1}
            >
              Volgende activiteiten
            </button>
          </div>
        </>
      )}

    </section>
  );
}

function HeartIcon() {
  return (
    <svg viewBox="0 0 16 16" aria-hidden="true">
      <path d="M8 13.2 2.6 8.1A3.5 3.5 0 1 1 8 3.8a3.5 3.5 0 1 1 5.4 4.3L8 13.2Z" />
    </svg>
  );
}

ActivityCarousel.propTypes = {
  products: PropTypes.array.isRequired,
  allProducts: PropTypes.array.isRequired,
  filters: PropTypes.object.isRequired,
  setFilters: PropTypes.func.isRequired,
  plan: PropTypes.object.isRequired,
  currency: PropTypes.string,
  onConfirmAdd: PropTypes.func.isRequired,
  onToggleFavorite: PropTypes.func,
  isFavorite: PropTypes.func,
  timeOptions: PropTypes.array.isRequired,
  plannerConfig: PropTypes.object,
  isLoading: PropTypes.bool,
  mobileFlowContent: PropTypes.node,
};

ActivityCarousel.defaultProps = {
  currency: "EUR",
  onToggleFavorite: () => {},
  isFavorite: () => false,
  plannerConfig: null,
  isLoading: false,
  mobileFlowContent: null,
};

function handleDragStart(event, product) {
  if (!event.dataTransfer) {
    return;
  }
  const payload = JSON.stringify({ productId: product.id });
  event.dataTransfer.effectAllowed = "copy";
  event.dataTransfer.setData("application/x-sbdp-product", payload);
  event.dataTransfer.setData("text/plain", String(product.id));
}

function isInteractiveTarget(target) {
  return Boolean(
    target instanceof Element &&
      target.closest("button, a, input, select, textarea, summary, [role='button']")
  );
}

function SearchIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <circle cx="7" cy="7" r="5" stroke="currentColor" strokeWidth="1.5" />
      <line
        x1="10.8"
        y1="10.8"
        x2="15"
        y2="15"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
      />
    </svg>
  );
}

function CloseIcon() {
  return (
    <svg
      width="12"
      height="12"
      viewBox="0 0 12 12"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <path
        d="M3 3l6 6M9 3 3 9"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
      />
    </svg>
  );
}
































