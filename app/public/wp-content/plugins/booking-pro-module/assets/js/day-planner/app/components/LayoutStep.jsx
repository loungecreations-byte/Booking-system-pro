import React, { useEffect, useMemo, useState } from "react";

import { usePlanner } from "../../store/PlannerProvider.jsx";
import { useUserProfile } from "../context/UserProfileContext.jsx";
import { generateTimeOptions } from "../utils/time.js";
import {
  createSearchEntry,
  evaluateSearchEntry,
  getProductCategoryTokens,
  prepareSearchQuery,
} from "../utils/search.js";
import { buildPlannerInsights } from "../utils/planner-engine.js";
import { buildPlannerCtaModel } from "../utils/planner-cta.js";
import { getDurationMinutes, getEnvironmentTag } from "../utils/products.js";
import { formatPrice, getSlotPricePerPerson } from "../../shared/booking.js";
import { emitPlannerEvent } from "../utils/telemetry.js";
import ActivityCarousel from "./ActivityCarousel.jsx";
import TimelinePanel from "./TimelinePanel.jsx";

const PRICE_FILTERS = {
  budget: { min: 0, max: 50 },
  mid: { min: 50, max: 100 },
  premium: { min: 100, max: Number.POSITIVE_INFINITY },
};

function readPendingPrefillCount() {
  if (typeof window === "undefined" || typeof window.sessionStorage === "undefined") {
    return 0;
  }

  try {
    const raw = window.sessionStorage.getItem("sbdpPlannerPrefillQueue");
    if (!raw) {
      return 0;
    }

    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed.length : 0;
  } catch (error) {
    return 0;
  }
}

function readPendingPrefillEntries() {
  if (typeof window === "undefined" || typeof window.sessionStorage === "undefined") {
    return [];
  }

  try {
    const raw = window.sessionStorage.getItem("sbdpPlannerPrefillQueue");
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    return [];
  }
}

function toPositiveInt(value) {
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

function resolvePendingProductTitle(entry, products) {
  const productId = toPositiveInt(entry?.product_id ?? entry?.productId ?? entry?.id);
  const product = Array.isArray(products)
    ? products.find((item) => Number(item?.id) === Number(productId))
    : null;
  const explicitTitle =
    typeof entry?.title === "string" && entry.title.trim() !== ""
      ? entry.title.trim()
      : typeof entry?.name === "string" && entry.name.trim() !== ""
        ? entry.name.trim()
        : "";

  return explicitTitle || product?.title || product?.name || "Gekozen activiteit";
}

function buildPendingPrefillPreview(products) {
  if (typeof window === "undefined") {
    return { labels: [], participants: null };
  }

  const queue = readPendingPrefillEntries();
  const bootPrefill = window.SBDP_DAY_PLANNER?.prefill || null;
  const sourceEntry = queue[0] || bootPrefill || null;
  if (!sourceEntry || typeof sourceEntry !== "object") {
    return { labels: [], participants: null };
  }

  const labels = [];
  const pushLabel = (value) => {
    if (typeof value !== "string") {
      return;
    }
    const trimmed = value.trim();
    if (trimmed !== "" && !labels.includes(trimmed)) {
      labels.push(trimmed);
    }
  };

  pushLabel(resolvePendingProductTitle(sourceEntry, products));

  const combiItems = Array.isArray(sourceEntry?.combi_items)
    ? sourceEntry.combi_items
    : Array.isArray(sourceEntry?.combiItems)
      ? sourceEntry.combiItems
      : Array.isArray(sourceEntry?.options?.combiItems)
        ? sourceEntry.options.combiItems
        : [];

  combiItems.forEach((item) => {
    if (item && typeof item === "object") {
      pushLabel(item.label || item.title || item.name || "");
    }
  });

  return {
    labels,
    participants: toPositiveInt(sourceEntry?.participants ?? sourceEntry?.people),
  };
}

export default function LayoutStep() {
  const {
    state,
    selectors,
    actions: {
      setFilters,
      startPlanning,
      addActivity,
      updateActivity,
      removeActivity,
      submitPlan,
      addToCart,
      clearToast,
      showToast,
      handleAlternativeSwitch,
      clearPlan,
    },
  } = usePlanner();
  const {
    actions: { toggleFavorite, isFavorite },
  } = useUserProfile();

  const plannerOpenHours = state.config?.open_hours || null;
  const plannerStep = state.config?.time_step_minutes;

  const searchQuery = useMemo(
    () => prepareSearchQuery(state.filters.search),
    [state.filters.search]
  );

  const filteredProducts = useMemo(() => {
    const durationFilter = state.filters.duration;
    const categoryFilter = state.filters.category;
    const priceFilter = state.filters.price;
    const environmentFilter = state.filters.environment;

    const result = state.products.filter((product) => {
      const searchEntry = createSearchEntry(product);
      const evaluation = evaluateSearchEntry(searchEntry, searchQuery);

      if (!evaluation.matches) {
        return false;
      }

      const durationMinutes = getDurationMinutes(product) ?? 0;
      if (durationFilter === "short" && durationMinutes > 60) {
        return false;
      }
      if (durationFilter === "medium" && (durationMinutes <= 60 || durationMinutes > 120)) {
        return false;
      }
      if (durationFilter === "long" && durationMinutes <= 120) {
        return false;
      }

      if (categoryFilter && categoryFilter !== "all") {
        const categoryTokens = getProductCategoryTokens(product);
        if (!categoryTokens.includes(categoryFilter)) {
          return false;
        }
      }

      if (priceFilter && priceFilter !== "all") {
        const pricePerPerson = getSlotPricePerPerson(product, 1, { sourceProduct: product });
        if (!Number.isFinite(pricePerPerson) || pricePerPerson <= 0) {
          return false;
        }

        const rules = PRICE_FILTERS[priceFilter];
        if (rules) {
          if (Number.isFinite(rules.min) && pricePerPerson < rules.min) {
            return false;
          }
          if (Number.isFinite(rules.max) && pricePerPerson > rules.max) {
            return false;
          }
        }
      }

      if (environmentFilter && environmentFilter !== "both") {
        const tag = getEnvironmentTag(product);
        if (environmentFilter === "indoor" && tag === "outdoor") {
          return false;
        }
        if (environmentFilter === "outdoor" && tag === "indoor") {
          return false;
        }
      }

      return true;
    });

    return result;
  }, [state.products, state.filters, searchQuery]);
  const catalogProducts = useMemo(() => {
    const selected = [];
    const selectedIds = new Set();
    (Array.isArray(state.plan.items) ? state.plan.items : []).forEach((item) => {
      const productId = item?.productId;
      if (!Number.isFinite(Number(productId))) {
        return;
      }
      const match = state.products.find((product) => Number(product.id) === Number(productId));
      if (match && !selectedIds.has(String(match.id))) {
        selectedIds.add(String(match.id));
        selected.push(match);
      }
    });

    const byId = new Map();
    [...selected, ...filteredProducts].forEach((product) => {
      if (product && product.id !== undefined && product.id !== null) {
        byId.set(String(product.id), product);
      }
    });

    return Array.from(byId.values());
  }, [filteredProducts, state.plan.items, state.products]);
  const [mobilePlannerOpen, setMobilePlannerOpen] = useState(false);
  const [pendingPrefillCount, setPendingPrefillCount] = useState(() => readPendingPrefillCount());

  useEffect(() => {
    emitPlannerEvent("sbdp:planner/panel-toggle", {
      panel: "timeline",
      state: mobilePlannerOpen ? "open" : "closed",
      source: "mobile_drawer",
    });
  }, [mobilePlannerOpen]);

  useEffect(() => {
    if (typeof window === "undefined") {
      return undefined;
    }

    const syncPendingPrefillCount = () => {
      setPendingPrefillCount(readPendingPrefillCount());
    };

    syncPendingPrefillCount();
    window.addEventListener("sbdp:planner/domain-updated", syncPendingPrefillCount);
    window.addEventListener("storage", syncPendingPrefillCount);
    const intervalId = window.setInterval(syncPendingPrefillCount, 300);

    return () => {
      window.removeEventListener("sbdp:planner/domain-updated", syncPendingPrefillCount);
      window.removeEventListener("storage", syncPendingPrefillCount);
      window.clearInterval(intervalId);
    };
  }, []);

  const fallbackTimeOptions = useMemo(() => {
    if (plannerOpenHours?.start && plannerOpenHours?.end) {
      return generateTimeOptions(plannerOpenHours, plannerStep);
    }

    return [];
  }, [plannerOpenHours?.start, plannerOpenHours?.end, plannerStep]);

  const timeOptions = useMemo(() => {
    if (Array.isArray(state.timeOptions) && state.timeOptions.length > 0) {
      return state.timeOptions;
    }

    return fallbackTimeOptions;
  }, [state.timeOptions, fallbackTimeOptions]);

  const [queuePending, setQueuePending] = useState(false);
  const [planPending, setPlanPending] = useState(false);

  useEffect(() => {
    const hasValidDate = typeof state.form?.date === "string" && state.form.date.trim() !== "";
    const participantsValue = Number.parseInt(state.form?.participants, 10);
    const hasValidParticipants = Number.isFinite(participantsValue) && participantsValue > 0;
    if (state.plan.days.length === 0 && !state.loading?.config && hasValidDate && hasValidParticipants) {
      startPlanning();
    }
  }, [
    startPlanning,
    state.form?.date,
    state.form?.participants,
    state.loading?.config,
    state.plan.days.length,
  ]);

  const plannerInsights = useMemo(
    () =>
      buildPlannerInsights({
        plan: state.plan,
        products: state.products,
        config: state.config,
      }),
    [state.plan, state.products, state.config]
  );

  const handleAddActivity = (productId, payload) => {
    return addActivity({ productId, ...payload });
  };

  const handleDropProduct = (payload) => {
    const { productId, dayIndex, startTime } = payload || {};
    if (!productId || typeof dayIndex !== "number" || !startTime) {
      showToast("Plaatsing mislukt. Probeer het opnieuw.");
      return false;
    }

    return addActivity({ productId, dayIndex, startTime });
  };

  const activityCount = Array.isArray(state.plan.items) ? state.plan.items.length : 0;
  const participantCount = Number.isFinite(selectors?.canonicalParticipants)
    ? selectors.canonicalParticipants
    : 0;
  const plannerActionState = selectors?.plannerActionState || {};
  const totalPrice = Number.isFinite(state.summary?.grandTotal)
    ? state.summary.grandTotal
    : Number.isFinite(state.summary?.subtotal)
    ? state.summary.subtotal
    : 0;
  const currency = state.summary?.currency || "EUR";
  const formattedTotal = formatPrice(totalPrice, currency);
  const programValidItems = Array.isArray(state.plan.items)
    ? state.plan.items.filter(
        (item) => Number.isFinite(item?.startMinutes) && Number.isFinite(item?.endMinutes)
      )
    : [];
  const programStartMinutes =
    programValidItems.length > 0
      ? Math.min(...programValidItems.map((item) => item.startMinutes))
      : null;
  const programEndMinutes =
    programValidItems.length > 0
      ? Math.max(...programValidItems.map((item) => item.endMinutes))
      : null;
  const programTimeRange =
    Number.isFinite(programStartMinutes) && Number.isFinite(programEndMinutes)
      ? `${String(Math.floor(programStartMinutes / 60)).padStart(2, "0")}:${String(programStartMinutes % 60).padStart(2, "0")}-${String(Math.floor(programEndMinutes / 60)).padStart(2, "0")}:${String(programEndMinutes % 60).padStart(2, "0")}`
      : null;
  const hasStartTimes = Array.isArray(state.plan.items)
    ? state.plan.items.some((item) => Boolean(item?.startTime))
    : false;
  const readyForCheckout = Boolean(plannerActionState.requirements_met);
  const plannerRadar = useMemo(() => {
    const summary = plannerInsights.summary || {};
    const conflictCount = Number.isFinite(summary.criticalConflictCount)
      ? summary.criticalConflictCount
      : Number.isFinite(summary.conflictCount)
      ? summary.conflictCount
      : 0;
    const advisoryConflictCount = Number.isFinite(summary.advisoryConflictCount)
      ? summary.advisoryConflictCount
      : 0;
    const gapCount = Number.isFinite(summary.gapCount) ? summary.gapCount : 0;
    const days = Array.isArray(plannerInsights.days) ? plannerInsights.days : [];
    const firstConflict =
      days.flatMap((day) => (day?.conflicts || []).filter((entry) => entry?.tone === "critical")).find(Boolean) ||
      days.flatMap((day) => (day?.conflicts || []).filter((entry) => entry?.tone !== "critical")).find(Boolean) ||
      null;
    const firstRouteWarning = days.flatMap((day) => day?.routeWarnings || []).find(Boolean) || null;
    const firstGapSuggestion = days.flatMap((day) => day?.gapSuggestions || []).find(Boolean) || null;
    const topMessage =
      Array.isArray(summary.topMessages) && summary.topMessages[0]
        ? summary.topMessages[0]
        : null;

    if (conflictCount > 0) {
      return {
        tone: "error",
        label: conflictCount === 1 ? "1 fout" : `${conflictCount} fouten`,
        message:
          firstConflict?.suggestion ||
          firstConflict?.message ||
          topMessage ||
          "Los eerst de overlap of ongeldige planning op.",
      };
    }

    if (advisoryConflictCount > 0) {
      return {
        tone: "warning",
        label: advisoryConflictCount === 1 ? "1 aandachtspunt" : `${advisoryConflictCount} aandachtspunten`,
        message:
          firstConflict?.suggestion ||
          firstConflict?.message ||
          topMessage ||
          "Je planning kan nog strakker. Controleer de aandachtspunten.",
      };
    }

    if (!state.form?.date || participantCount <= 0 || activityCount <= 0 || !hasStartTimes) {
      return {
        tone: "warning",
        label: "Nog niet compleet",
        message: "Kies een datum, deelnemers en minstens één activiteit met een starttijd.",
      };
    }

    if (gapCount > 0) {
      return {
        tone: "warning",
        label: gapCount === 1 ? "1 aandachtspunt" : `${gapCount} aandachtspunten`,
        message:
          firstGapSuggestion?.reason ||
          firstGapSuggestion?.description ||
          firstRouteWarning?.suggestion ||
          firstRouteWarning?.message ||
          topMessage ||
          "Je planning kan nog strakker. Controleer de open momenten.",
      };
    }

    if (plannerActionState.action_mode === "request") {
      return {
        tone: "warning",
        label: plannerActionState.status_label || "Offerte vereist",
        message:
          plannerActionState.blocking_reason_message ||
          "Deze planning bevat activiteiten die niet direct afrekenbaar zijn.",
      };
    }

    if (plannerActionState.action_mode === "blocked") {
      return {
        tone: "error",
        label: plannerActionState.status_label || "Niet direct boekbaar",
        message:
          plannerActionState.blocking_reason_message ||
          "Deze planning kan momenteel niet worden afgerond.",
      };
    }

    return {
      tone: "ready",
      label: plannerActionState.status_label || "Klaar om af te ronden",
      message:
        plannerActionState.status_message ||
        "Je planning klopt en is klaar om te boeken of als offerte te versturen.",
    };
  }, [
    activityCount,
    hasStartTimes,
    participantCount,
    plannerActionState.action_mode,
    plannerActionState.blocking_reason_message,
    plannerActionState.status_label,
    plannerActionState.status_message,
    plannerInsights.days,
    plannerInsights.summary,
    state.form?.date,
  ]);
  const plannerStatusLabel = plannerRadar.label;
  const plannerFooterMessage =
    plannerRadar.message ||
    (readyForCheckout
      ? "Je planning staat klaar om te boeken."
      : "Kies een datum, voeg activiteiten toe en zorg dat je planning een starttijd heeft.");
  const catalogHydrationPending = Boolean(
    state.loading?.products && (!Array.isArray(state.products) || state.products.length === 0)
  );
  const prefillHydrationPending =
    pendingPrefillCount > 0 &&
    !state.loading?.config &&
    !state.loading?.plan &&
    activityCount === 0 &&
    !plannerActionState.requirements_met &&
    !plannerActionState.blocking_reason_message;
  const surfaceUpdating = Boolean(
    state.loading?.config ||
      catalogHydrationPending ||
      state.loading?.plan ||
      prefillHydrationPending
  );
  const pendingPrefillPreview = useMemo(
    () => (prefillHydrationPending ? buildPendingPrefillPreview(state.products) : { labels: [], participants: null }),
    [prefillHydrationPending, state.products]
  );
  const showPlannerPendingState = prefillHydrationPending && activityCount === 0;
  const showPlannerEmptyState = !showPlannerPendingState && activityCount === 0;
  const plannerCtaModel = buildPlannerCtaModel({
    plannerActionState,
    formattedTotal,
    queuePending,
    planPending,
    surfaceUpdating,
  });

  if (!state.plan.days.length) {
    if (showPlannerPendingState) {
      return (
        <section className="sbdp-planner-state-card sbdp-planner-state-card--pending" role="status" aria-live="polite">
          <div className="sbdp-planner-state-card__body">
            <p className="sbdp-planner-state-card__eyebrow">Programma laden</p>
            <h3>Je programma wordt geladen…</h3>
            <p>
              We zetten je gekozen activiteit en eventuele combi&apos;s klaar in de planner.
            </p>
            {pendingPrefillPreview.labels.length > 0 ? (
              <ul className="sbdp-planner-state-card__list" aria-label="Gekozen programma in verwerking">
                {pendingPrefillPreview.labels.map((label) => (
                  <li key={label}>{label}</li>
                ))}
              </ul>
            ) : null}
            {pendingPrefillPreview.participants ? (
              <p className="sbdp-planner-state-card__meta">
                Voor {pendingPrefillPreview.participants}{" "}
                {pendingPrefillPreview.participants === 1 ? "persoon" : "personen"}.
              </p>
            ) : null}
          </div>
        </section>
      );
    }

    return (
      <section className="sbdp-planner-state-card sbdp-planner-state-card--empty" aria-label="Lege dagplanning">
        <div className="sbdp-planner-state-card__body">
          <p className="sbdp-planner-state-card__eyebrow">Dagplanning</p>
          <h3>Nog niets in je dagplanning</h3>
          <p>Kies een datum en voeg activiteiten toe om jullie dag samen te stellen.</p>
        </div>
      </section>
    );
  }

  const handleAddToCart = async () => {
    if (!plannerActionState.primary_cta_enabled || queuePending || surfaceUpdating) {
      return;
    }

    setQueuePending(true);
    try {
      clearToast();
      await addToCart();
    } catch (error) {
      // addToCart reports booking errors via planner state and toasts.
    } finally {
      setQueuePending(false);
    }
  };

  const handleSubmitPlan = async () => {
    if (!plannerActionState.secondary_quote_enabled || planPending || surfaceUpdating) {
      return;
    }

    setPlanPending(true);
    try {
      await submitPlan();
    } finally {
      setPlanPending(false);
    }
  };

  const handleReviewPlan = () => {
    setMobilePlannerOpen(true);
    document
      .querySelector(".sbdp-day-planner__primary-status, .sbdp-planner-checkout__message--warning, .sbdp-planner-checkout__message--error")
      ?.scrollIntoView({ behavior: "smooth", block: "center" });
  };

  const handlePrimaryPlannerAction = () => {
    if (plannerCtaModel.primary.key === "checkout") {
      return handleAddToCart();
    }
    if (plannerCtaModel.primary.key === "quote") {
      return handleSubmitPlan();
    }
    if (plannerCtaModel.primary.key === "add") {
      document
        .querySelector(".sbdp-day-planner__results")
        ?.scrollIntoView({ behavior: "smooth", block: "start" });
      return undefined;
    }
    handleReviewPlan();
    return undefined;
  };

  const handleSecondaryPlannerAction = () => {
    if (plannerCtaModel.secondary?.key === "quote") {
      return handleSubmitPlan();
    }
    handleReviewPlan();
    return undefined;
  };

  const mobileFlowContent = (
    <div className="sbdp-mobile-planner-entry">
      <div className="sbdp-mobile-planner-entry__cta">
        <div>
          <p className="sbdp-mobile-planner-entry__eyebrow">Planner</p>
          <h4>Bekijk je daglijn</h4>
          <p>Open je planning, zie conflicts en pas je volgorde direct aan.</p>
        </div>
        <button
          type="button"
          className="ui-btn ui-btn--primary ui-btn--planner"
          onClick={() => {
            emitPlannerEvent("sbdp:planner/action", {
              action: "open-planner-panel",
              status: "button_click",
              source: "mobile_entry",
            });
            setMobilePlannerOpen(true);
          }}
        >
          Open planner
        </button>
      </div>
      <div className="sbdp-mobile-plan-compact">
        <div className="sbdp-mobile-plan-compact__item">
          <span>Activiteiten</span>
          <strong>{activityCount}</strong>
        </div>
        <div className="sbdp-mobile-plan-compact__item">
          <span>Deelnemers</span>
          <strong>{participantCount || "-"}</strong>
        </div>
        <div className="sbdp-mobile-plan-compact__item">
          <span>Totaal (indicatief)</span>
          <strong>{formattedTotal}</strong>
        </div>
      </div>
    </div>
  );

  return (
    <>
      <div className="sbdp-workspace__body sbdp-workspace__body--premium">
        <div className="sbdp-day-planner__columns sbdp-day-planner__columns--premium" role="tabpanel" aria-label="Planner">
          <div className="sbdp-day-planner__results">
              <div className="sbdp-day-planner__panel sbdp-day-planner__panel--list sbdp-day-planner__panel--premium">
                <div className="sbdp-day-planner__results-heading">
                <h3>Browse activiteiten</h3>
                <p className="sbdp-day-planner__results-hint">
                  Je kunt hier altijd extra activiteiten toevoegen, ook naast een combideal in je programma.
                </p>
                </div>
              <ActivityCarousel
                products={catalogProducts}
                allProducts={state.products}
                filters={state.filters}
                setFilters={setFilters}
                plan={state.plan}
                currency={state.summary?.currency || currency}
                onConfirmAdd={handleAddActivity}
                onToggleFavorite={toggleFavorite}
                isFavorite={isFavorite}
                timeOptions={timeOptions}
                plannerConfig={state.config}
                isLoading={state.loading.products}
                mobileFlowContent={mobileFlowContent}
              />
            </div>
          </div>
          <aside
            className={`sbdp-day-planner__primary sbdp-day-planner__primary--premium ${
              mobilePlannerOpen ? "is-mobile-open" : ""
            }`.trim()}
            data-planner-primary-surface="active"
          >
              <div className="sbdp-day-planner__primary-header">
                <div>
                  <h3>Jouw planning</h3>
                </div>
                <div className="sbdp-day-planner__primary-header-actions">
                  {state.plan.items.length > 0 && (
                    <button
                      type="button"
                      className="sbdp-btn sbdp-btn--text sbdp-btn--danger-link ui-btn ui-btn--ghost ui-btn--inline"
                      title="Verwijder alle activiteiten"
                      aria-label="Verwijder alle activiteiten uit je planning"
                      onClick={() => {
                        if (window.confirm("Weet je zeker dat je de hele planning wilt wissen? Dit kan niet ongedaan worden gemaakt.")) {
                          clearPlan();
                        }
                      }}
                    >
                      Verwijder alle activiteiten
                    </button>
                  )}
                  <div
                    className={`sbdp-day-planner__primary-status sbdp-day-planner__primary-status--${plannerRadar.tone}`.trim()}
                    role="status"
                    aria-live="polite"
                  >
                    <strong>{plannerRadar.label}</strong>
                    <span>{plannerRadar.message}</span>
                  </div>
                  <button
                    type="button"
                    className="sbdp-day-planner__mobile-dismiss"
                    onClick={() => setMobilePlannerOpen(false)}
                  >
                    Sluit
                  </button>
                </div>
              </div>
            <div className="sbdp-day-planner__primary-sticky">
              {showPlannerPendingState ? (
                <section className="sbdp-planner-state-card sbdp-planner-state-card--pending" role="status" aria-live="polite">
                  <div className="sbdp-planner-state-card__body">
                    <p className="sbdp-planner-state-card__eyebrow">Programma laden</p>
                    <h3>Je programma wordt geladen…</h3>
                    <p>
                      We zetten je gekozen activiteit en eventuele combi&apos;s klaar in de planner.
                    </p>
                    {pendingPrefillPreview.labels.length > 0 ? (
                      <ul className="sbdp-planner-state-card__list" aria-label="Gekozen programma in verwerking">
                        {pendingPrefillPreview.labels.map((label) => (
                          <li key={label}>{label}</li>
                        ))}
                      </ul>
                    ) : null}
                    {pendingPrefillPreview.participants ? (
                      <p className="sbdp-planner-state-card__meta">
                        Voor {pendingPrefillPreview.participants}{" "}
                        {pendingPrefillPreview.participants === 1 ? "persoon" : "personen"}.
                      </p>
                    ) : null}
                  </div>
                </section>
              ) : showPlannerEmptyState ? (
                <section className="sbdp-planner-state-card sbdp-planner-state-card--empty" aria-label="Lege dagplanning">
                  <div className="sbdp-planner-state-card__body">
                    <p className="sbdp-planner-state-card__eyebrow">Dagplanning</p>
                    <h3>Nog niets in je dagplanning</h3>
                    <p>Kies een datum en voeg activiteiten toe om jullie dag samen te stellen.</p>
                  </div>
                  <div className="sbdp-planner-state-card__actions">
                    <button
                      type="button"
                      className="ui-btn ui-btn--secondary"
                      onClick={() => {
                        document
                          .querySelector(".sbdp-day-planner__results")
                          ?.scrollIntoView({ behavior: "smooth", block: "start" });
                      }}
                    >
                      Bekijk activiteiten
                    </button>
                    <button
                      type="button"
                      className="ui-btn ui-btn--primary ui-btn--planner"
                      onClick={() => {
                        document
                          .querySelector(".sbdp-day-planner__hero")
                          ?.scrollIntoView({ behavior: "smooth", block: "start" });
                      }}
                    >
                      Start met plannen
                    </button>
                  </div>
                </section>
              ) : (
                <TimelinePanel
                  plan={state.plan}
                  products={state.products}
                  slotOptions={timeOptions}
                  openHours={plannerOpenHours}
                  updateActivity={updateActivity}
                  removeActivity={removeActivity}
                  showToast={showToast}
                  onDropProduct={handleDropProduct}
                  alternatives={state.alternatives}
                  onSwitchAlternative={handleAlternativeSwitch}
                />
              )}
              <section className="sbdp-planner-checkout" aria-label="Planner overzicht">
                <div className="sbdp-planner-checkout__summary">
                  <div className="sbdp-planner-checkout__meta">
                    <span>{activityCount} gekozen</span>
                    <span>{participantCount || "-"} deelnemers</span>
                    <span>{programTimeRange || "Tijd nog niet vast"}</span>
                    <span>{plannerStatusLabel}</span>
                  </div>
                  <strong className="sbdp-planner-checkout__total">{formattedTotal}</strong>
                </div>
                <p className="sbdp-planner-checkout__message">
                  {programTimeRange
                    ? `Je dag loopt van ${programTimeRange}.`
                    : "Zodra je programma tijdvakken heeft, zie je hier het complete tijdsvenster."}
                  {" "}
                  {plannerFooterMessage}
                </p>
                <p className="sbdp-planner-checkout__message">
                  {plannerCtaModel.priceLabel}
                </p>
                {surfaceUpdating ? (
                  <p className="sbdp-planner-checkout__message">
                    Planner wordt bijgewerkt. Wacht tot de actieve planning volledig is geladen voordat je boekt of een offerte aanvraagt.
                  </p>
                ) : null}
                {plannerActionState.blocking_reason_message ? (
                  <p
                    className={`sbdp-planner-checkout__message ${
                      plannerActionState.availability_issue_visible
                        ? "sbdp-planner-checkout__message--error"
                        : "sbdp-planner-checkout__message--warning"
                    }`.trim()}
                  >
                    {plannerActionState.blocking_reason_message}
                  </p>
                ) : null}
                <div className="sbdp-planner-checkout__actions">
                  <button
                    type="button"
                    className={`ui-btn ui-btn--${plannerCtaModel.primary.variant} ui-btn--planner`}
                    onClick={handlePrimaryPlannerAction}
                    disabled={!plannerCtaModel.primary.enabled}
                    aria-busy={plannerCtaModel.primary.busy ? "true" : "false"}
                    aria-label={plannerCtaModel.primary.ariaLabel}
                    data-planner-action={plannerCtaModel.primary.key}
                  >
                    {plannerCtaModel.primary.label}
                  </button>
                  {plannerCtaModel.secondary ? (
                    <button
                      type="button"
                      className={`ui-btn ui-btn--${plannerCtaModel.secondary.variant}`}
                      onClick={handleSecondaryPlannerAction}
                      disabled={!plannerCtaModel.secondary.enabled}
                      aria-busy={plannerCtaModel.secondary.busy ? "true" : "false"}
                      aria-label={plannerCtaModel.secondary.ariaLabel}
                      data-planner-action={plannerCtaModel.secondary.key}
                    >
                      {plannerCtaModel.secondary.label}
                    </button>
                  ) : null}
                </div>
              </section>
            </div>
          </aside>
          </div>
      </div>
      <div className="sbdp-mobile-action-bar" role="region" aria-label="Planner acties">
        <div className="sbdp-mobile-action-bar__meta">
          <strong>{activityCount} gekozen</strong>
          <span>{plannerInsights.summary.mobileLabel} · prijsindicatie {formattedTotal}</span>
        </div>
        <button
          type="button"
          className={`ui-btn ui-btn--${plannerCtaModel.primary.variant} ui-btn--planner`}
          onClick={() => {
            emitPlannerEvent("sbdp:planner/action", {
              action: plannerCtaModel.primary.key,
              status: "button_click",
              source: "mobile_sticky_bar",
            });
            handlePrimaryPlannerAction();
          }}
          disabled={!plannerCtaModel.primary.enabled}
          aria-busy={plannerCtaModel.primary.busy ? "true" : "false"}
          aria-label={plannerCtaModel.primary.ariaLabel}
          data-planner-action={plannerCtaModel.primary.key}
        >
          {plannerCtaModel.primary.label}
        </button>
      </div>
    </>
  );
}
