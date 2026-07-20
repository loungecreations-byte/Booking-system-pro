
import React, { useEffect, useMemo, useState } from "react";

import { usePlanner } from "../../store/PlannerProvider.jsx";
import { formatDateLabel } from "../utils/time.js";
import { buildPlannerInsights } from "../utils/planner-engine.js";
import { buildPlannerCtaModel } from "../utils/planner-cta.js";
import { emitPlannerEvent } from "../utils/telemetry.js";
import { formatPrice } from "../../shared/booking.js";
import { formatTimeRange } from "../utils/program.js";

const EMPTY_MESSAGE = "Nog geen activiteiten geselecteerd.";

export default function SummaryPanel() {
  const {
    state,
    selectors,
    actions: { submitPlan, addToCart, clearToast },
  } = usePlanner();

  const { summary, plan, form, toast, error, products, config } = state;
  const plannerActionState = selectors?.plannerActionState || {};

  const planItems = Array.isArray(plan.items) ? plan.items : [];
  const hasItems = planItems.length > 0;
  const plannerInsights = useMemo(
    () =>
      buildPlannerInsights({
        plan,
        products,
        config,
      }),
    [plan, products, config]
  );

  const participantCount = Number.isFinite(selectors?.canonicalParticipants)
    ? selectors.canonicalParticipants
    : 0;

  const grandTotal = useMemo(() => {
    if (Number.isFinite(summary?.grandTotal)) {
      return summary.grandTotal;
    }

    if (Number.isFinite(summary?.subtotal)) {
      return summary.subtotal;
    }

    return null;
  }, [summary?.grandTotal, summary?.subtotal]);

  const currency = summary?.currency || "EUR";
  const locale = summary?.locale || "nl-NL";

  const participantShare = useMemo(() => {
    if (Number.isFinite(summary?.participantShare)) {
      return summary.participantShare;
    }

    return null;
  }, [summary?.participantShare, grandTotal, participantCount]);

  const checklist = useMemo(
    () => [
      {
        key: "date",
        label: "Datum gekozen",
        complete: Boolean(form?.date),
      },
      {
        key: "time",
        label: "Starttijd geselecteerd",
        complete: planItems.some((item) => Boolean(item.startTime)),
      },
      {
        key: "participants",
        label: "Aantal deelnemers ingevuld",
        complete: participantCount > 0,
      },
      {
        key: "activities",
        label: "Minstens een activiteit toegevoegd",
        complete: hasItems,
      },
    ],
    [form?.date, planItems, participantCount, hasItems]
  );

  const pendingChecklist = checklist.filter((item) => !item.complete);
  const readyForCheckout = Boolean(plannerActionState.requirements_met);

  const buildLineKey = (productId, start, date, fallbackIndex, hash) => {
    if (hash) {
      return `hash:${hash}`;
    }
    const safeProduct = Number.isFinite(productId) ? productId : `idx-${fallbackIndex}`;
    const safeStart = start ?? "";
    const safeDate = date ?? "";
    return `${safeProduct}|${safeDate}|${safeStart}`;
  };

  const lineItemLookup = useMemo(() => {
    const map = new Map();
    const items = Array.isArray(summary?.items) ? summary.items : [];

    items.forEach((line, index) => {
      if (!line || typeof line !== "object") {
        return;
      }

      const rawProductId = line.product_id ?? line.productId ?? line.id;
      const productId = Number.parseInt(rawProductId, 10);
      const start =
        typeof line.schedule?.start === "string" && line.schedule.start.trim() !== ""
          ? line.schedule.start.trim()
          : "";

      const scheduleDate =
        typeof line.schedule?.date === "string" && line.schedule.date.trim() !== ""
          ? line.schedule.date.trim()
          : "";
      const hash =
        typeof line.line_uid === "string"
          ? line.line_uid
          : typeof line.lineHash === "string"
          ? line.lineHash
          : typeof line.id === "string"
          ? line.id
          : null;

      const key = buildLineKey(productId, start, scheduleDate, index, hash);
      if (!map.has(key)) {
        map.set(key, line);
      }
    });

    return map;
  }, [summary?.items]);

  const resolveLineItem = (planItem, index) => {
    const rawProductId = planItem?.productId ?? planItem?.product_id;
    const productId = Number.parseInt(rawProductId, 10);
    const start = typeof planItem?.startTime === "string" ? planItem.startTime : "";
    const date =
      typeof planItem?.date === "string" && planItem.date.trim() !== ""
        ? planItem.date.trim()
        : (() => {
            if (
              Number.isFinite(planItem?.dayIndex) &&
              planItem.dayIndex >= 0 &&
              Array.isArray(plan.days) &&
              plan.days[planItem.dayIndex]
            ) {
              const dayDate = plan.days[planItem.dayIndex].date;
              return typeof dayDate === "string" ? dayDate : "";
            }
            return "";
          })();
    const hash = typeof planItem?.id === "string" ? planItem.id : null;

    const key = buildLineKey(productId, start, date, index, hash);

    if (lineItemLookup.has(key)) {
      return lineItemLookup.get(key);
    }

    const items = Array.isArray(summary?.items) ? summary.items : [];
    return items[index] || null;
  };

  const stats = useMemo(() => {
    const baseStats = [
      { label: "Deelnemers", value: participantCount },
      { label: "Activiteiten", value: planItems.length },
      {
        label: "Totaal (indicatief)",
        value: Number.isFinite(grandTotal) ? formatPrice(grandTotal, currency) : "Prijs wordt berekend",
      },
    ];

    if (participantShare !== null && participantCount > 0) {
      baseStats.push({
        label: `Per deelnemer (${participantCount})`,
        value: formatPrice(participantShare, currency),
      });
    }

    return baseStats;
  }, [participantCount, planItems.length, participantShare, currency, grandTotal]);

  const summaryMetaLabel = useMemo(() => {
    const dayCount = plan.days.length;
    const activityCount = planItems.length;
    return `${dayCount} dag(en) | ${activityCount} activiteit(en)`;
  }, [plan.days.length, planItems.length]);

  const message = error || toast;
  const messageTone = error ? "error" : "info";

  const handleDismissMessage = () => {
    if (toast) {
      emitPlannerEvent("sbdp:planner/action", {
        action: "message-dismiss",
        status: "toast",
      });
      clearToast();
    }
  };

  const handleAddToCart = async () => {
    emitPlannerEvent("sbdp:planner/action", {
      action: "queue",
      status: "button_click",
      source: "summary_panel",
      items_count: planItems.length,
      total_value: grandTotal,
    });
    if (!plannerActionState.primary_cta_enabled || queuePending) {
      return;
    }

    setQueuePending(true);
    try {
      clearToast();
      await addToCart();
    } catch (error) {
      // addToCart already surfaces errors through planner state
    } finally {
      setQueuePending(false);
    }
  };

  const handleSubmitPlan = async () => {
    emitPlannerEvent("sbdp:planner/action", {
      action: "request-quote",
      status: "button_click",
      source: "summary_panel",
      items_count: planItems.length,
      total_value: grandTotal,
    });
    if (!plannerActionState.secondary_quote_enabled || planPending) {
      return;
    }

    setPlanPending(true);
    try {
      await submitPlan();
    } catch (error) {
      // submitPlan handles its own error reporting
    } finally {
      setPlanPending(false);
    }
  };

  const suggestion = useMemo(() => {
    const smartSuggestion = plannerInsights.summary.topSuggestion;
    if (smartSuggestion?.productTitle && smartSuggestion?.startTime) {
      return `${smartSuggestion.title}: ${smartSuggestion.productTitle} past logisch rond ${smartSuggestion.startTime}.`;
    }

    if (plannerInsights.summary.topMessages[0]) {
      return plannerInsights.summary.topMessages[0];
    }

    if (!hasItems) {
      return "Tip: Voeg een activiteit toe via het overzicht hiernaast.";
    }

    const tokens = new Set();

    const collectTokens = (tokenSource) => {
      if (!tokenSource) {
        return;
      }
      const values = Array.isArray(tokenSource) ? tokenSource : [tokenSource];
      values.forEach((value) => {
        if (typeof value !== "string") {
          return;
        }
        const cleaned = value.trim().toLowerCase();
        if (cleaned) {
          tokens.add(cleaned);
        }
      });
    };

    if (Array.isArray(summary?.items)) {
      summary.items.forEach((item) => {
        collectTokens(item?.categories);
        collectTokens(item?.category_slugs);
        collectTokens(item?.category);
      });
    }

    planItems.forEach((item) => {
      collectTokens(item?.categories);
      collectTokens(item?.category_slugs);
    });

    const hasFood = Array.from(tokens).some((token) => token.includes("eten") || token.includes("food") || token.includes("lunch"));
    const hasTransport = Array.from(tokens).some((token) => token.includes("vervoer") || token.includes("transport"));

    if (!hasFood) {
      return "Tip: Voeg een eetmoment toe zodat iedereen tevreden blijft.";
    }

    if (!hasTransport) {
      return "Tip: Plan eventueel vervoer of extra logistiek.";
    }

    return "Je planning is bijna klaar. Boek direct of vraag een offerte aan.";
  }, [hasItems, planItems, summary?.items, plannerInsights]);

  const [queuePending, setQueuePending] = useState(false);
  const [planPending, setPlanPending] = useState(false);
  const [isExpanded, setIsExpanded] = useState(false);

  const totalDurationMinutes = useMemo(
    () =>
      planItems.reduce((minutes, item) => {
        const start = Number.isFinite(item?.startMinutes) ? item.startMinutes : 0;
        const end = Number.isFinite(item?.endMinutes) ? item.endMinutes : start;
        return minutes + Math.max(0, end - start);
      }, 0),
    [planItems]
  );

  const formattedDuration = useMemo(() => {
    if (!Number.isFinite(totalDurationMinutes) || totalDurationMinutes <= 0) {
      return null;
    }
    if (totalDurationMinutes >= 120) {
      const hours = Math.floor(totalDurationMinutes / 60);
      const remainder = totalDurationMinutes % 60;
      return remainder ? `${hours}u ${remainder}m` : `${hours} uur`;
    }
    if (totalDurationMinutes >= 60) {
      return `${(totalDurationMinutes / 60).toFixed(1)} uur`;
    }
    return `${totalDurationMinutes} min`;
  }, [totalDurationMinutes]);

  const summaryHighlights = useMemo(() => {
    const highlights = [
      {
        key: "activities",
        label: "Activiteiten",
        Icon: SummaryActivitiesIcon,
        value: String(planItems.length),
      },
      {
        key: "participants",
        label: "Deelnemers",
        Icon: SummaryParticipantsIcon,
        value: participantCount > 0 ? String(participantCount) : "-",
      },
    ];

    if (formattedDuration) {
      highlights.push({
        key: "duration",
        label: "Tijdsduur",
        Icon: SummaryDurationIcon,
        value: formattedDuration,
      });
    }

    return highlights;
  }, [planItems.length, participantCount, formattedDuration]);

  const programConfidence = useMemo(() => {
    const validItems = planItems.filter(
      (item) => Number.isFinite(item?.startMinutes) && Number.isFinite(item?.endMinutes)
    );
    const startMinutes = validItems.length > 0 ? Math.min(...validItems.map((item) => item.startMinutes)) : null;
    const endMinutes = validItems.length > 0 ? Math.max(...validItems.map((item) => item.endMinutes)) : null;
    const fixedCount = planItems.filter((item) => item?.locked || item?.role === "anchor").length;
    const flexibleCount = Math.max(0, planItems.length - fixedCount);
    const criticalConflictCount = Number.isFinite(plannerInsights.summary?.criticalConflictCount)
      ? plannerInsights.summary.criticalConflictCount
      : Number.isFinite(plannerInsights.summary?.conflictCount)
      ? plannerInsights.summary.conflictCount
      : 0;
    const ready =
      plannerActionState.action_mode === "direct" &&
      criticalConflictCount === 0;

    return {
      title: plan.days.length > 0 ? `Dagprogramma ${plan.days[0].date}` : "Dagprogramma",
      timeRange:
        Number.isFinite(startMinutes) && Number.isFinite(endMinutes)
          ? `${formatTimeRange(startMinutes, endMinutes)}`
          : null,
      duration: formattedDuration,
      fixedCount,
      flexibleCount,
      ready,
      label: ready
        ? "Programma is logisch en boekbaar"
        : plannerActionState.status_label || "Programma heeft nog aandacht nodig",
    };
  }, [
    plan.days,
    planItems,
    plannerActionState.action_mode,
    plannerActionState.status_label,
    plannerInsights.summary.criticalConflictCount,
    plannerInsights.summary.conflictCount,
    formattedDuration,
  ]);

  useEffect(() => {
    if (toast || error) {
      setIsExpanded(true);
    }
  }, [toast, error]);

  const summaryBodyId = "sbdp-summary-bar-body";
  const toggleAssistiveLabel = isExpanded ? "Verberg jouw planning" : "Toon jouw planning";
  const formattedTotal = Number.isFinite(grandTotal)
    ? formatPrice(grandTotal, currency)
    : "Prijs wordt berekend";
  const plannerCtaModel = buildPlannerCtaModel({
    plannerActionState,
    formattedTotal: Number.isFinite(grandTotal) ? formattedTotal : "",
    queuePending,
    planPending,
  });

  const handleReviewPlan = () => {
    setIsExpanded(true);
    document
      .querySelector(".sbdp-day-planner__primary-status, .sbdp-summary-bar__program-confidence")
      ?.scrollIntoView({ behavior: "smooth", block: "center" });
  };

  const handlePrimaryAction = () => {
    if (plannerCtaModel.primary.key === "checkout") {
      return handleAddToCart();
    }
    if (plannerCtaModel.primary.key === "quote") {
      return handleSubmitPlan();
    }
    handleReviewPlan();
    return undefined;
  };

  const handleSecondaryAction = () => {
    if (plannerCtaModel.secondary?.key === "quote") {
      return handleSubmitPlan();
    }
    handleReviewPlan();
    return undefined;
  };

  return (
    <aside
      className={`sbdp-summary-bar ${isExpanded ? "is-expanded" : ""}`}
      aria-live="polite"
    >
      <button
        type="button"
        className="sbdp-summary-bar__toggle"
        onClick={() => {
          const nextValue = !isExpanded;
          emitPlannerEvent("sbdp:planner/action", {
            action: "summary_toggle",
            status: nextValue ? "expanded" : "collapsed",
            source: "summary_panel",
          });
          setIsExpanded(nextValue);
        }}
        aria-expanded={isExpanded ? "true" : "false"}
        aria-controls={summaryBodyId}
        aria-label={toggleAssistiveLabel}
      >
          <div className="sbdp-summary-bar__toggle-main">
          <div className="sbdp-summary-bar__toggle-meta">
            <span className="sbdp-summary-bar__toggle-title">
              {plannerActionState.action_mode === "request"
                ? "Programma & voorlopige richtprijs"
                : "Programma & prijsindicatie"}
            </span>
            <span className="sbdp-summary-bar__toggle-total">
              {formattedTotal}
            </span>
          </div>
          <div className="sbdp-summary-bar__toggle-metrics" aria-hidden="true">
            {summaryHighlights.map((item) => (
              <span key={item.key} className="sbdp-summary-bar__toggle-chip">
                <span className="sbdp-summary-bar__toggle-chip-icon">{item.icon}</span>
                <span>{item.value}</span>
              </span>
            ))}
          </div>
        </div>
        <span className="sbdp-summary-bar__toggle-icon" aria-hidden="true">
          <ChevronIcon expanded={isExpanded} />
        </span>
      </button>

      <div className="sbdp-summary-bar__actions sbdp-summary-bar__actions--quick">
        <button
          type="button"
          className={`ui-btn ui-btn--${plannerCtaModel.primary.variant} ui-btn--planner`}
          onClick={handlePrimaryAction}
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
            onClick={handleSecondaryAction}
            disabled={!plannerCtaModel.secondary.enabled}
            aria-busy={plannerCtaModel.secondary.busy ? "true" : "false"}
            aria-label={plannerCtaModel.secondary.ariaLabel}
            data-planner-action={plannerCtaModel.secondary.key}
          >
            {plannerCtaModel.secondary.label}
          </button>
        ) : null}
      </div>
      {plannerActionState.blocking_reason_message ? (
        <p className="sbdp-summary-bar__hint">{plannerActionState.blocking_reason_message}</p>
      ) : null}

      <div
        id={summaryBodyId}
        className="sbdp-summary-bar__body"
        data-open={isExpanded ? "true" : "false"}
        aria-hidden={isExpanded ? "false" : "true"}
      >
        <header className="sbdp-summary-bar__header">
          <div>
            <h2>Programma & prijsoverzicht</h2>
            <p className="sbdp-summary-bar__subtitle">{summaryMetaLabel} · klik buiten de balk om in te klappen</p>
          </div>
          <div className="sbdp-summary-bar__chip">{formattedTotal}</div>
        </header>

        <p className="sbdp-summary-bar__hint">
          {plannerCtaModel.priceLabel}
        </p>
        {message ? (
          <div className={`sbdp-summary-bar__alert sbdp-summary-bar__alert--${messageTone}`}>
            <span>{message}</span>
            {toast ? (
              <button type="button" onClick={handleDismissMessage} aria-label="Melding sluiten">
                <CloseSmallIcon />
            </button>
          ) : null}
        </div>
        ) : null}

        <section className="sbdp-summary-bar__program-confidence" aria-label="Programma vertrouwen">
          <div className="sbdp-summary-bar__program-confidence-main">
            <span className="sbdp-summary-bar__program-confidence-eyebrow">Programma check</span>
            <strong>{programConfidence.title}</strong>
            <p>
              {programConfidence.timeRange ? `${programConfidence.timeRange} · ` : ""}
              {programConfidence.duration ? `${programConfidence.duration} · ` : ""}
              {programConfidence.fixedCount} vast · {programConfidence.flexibleCount} flexibel
            </p>
          </div>
          <div className={`sbdp-summary-bar__program-confidence-pill ${programConfidence.ready ? "is-ready" : "is-pending"}`}>
            {programConfidence.label}
          </div>
        </section>

        <ul className="sbdp-summary-bar__checklist">
          {checklist.map((item) => (
            <li
              key={item.key}
              className={`sbdp-summary-bar__check-item ${item.complete ? "is-complete" : "is-pending"}`}
            >
              <span className="sbdp-summary-bar__check-icon" aria-hidden="true">
                <ChecklistIcon complete={item.complete} />
              </span>
              <span>{item.label}</span>
            </li>
          ))}
        </ul>

        {pendingChecklist.length > 0 ? (
          <p className="sbdp-summary-bar__hint">
            Maak de bovenstaande stappen af om direct te kunnen boeken.
          </p>
        ) : null}

        {plannerInsights.summary.criticalConflictCount > 0 ||
        plannerInsights.summary.conflictCount > 0 ||
        plannerInsights.summary.advisoryConflictCount > 0 ||
        plannerInsights.summary.gapCount > 0 ? (
          <div className="sbdp-summary-bar__planner-insights" aria-label="Planner inzichten">
            {plannerInsights.days
              .flatMap((day) => [
                ...(day.conflicts || [])
                  .filter((entry) => entry?.tone === "critical")
                  .slice(0, 1)
                  .map((entry) => ({
                  id: entry.id,
                  tone: entry.tone,
                  label: entry.message,
                })),
                ...(day.conflicts || [])
                  .filter((entry) => entry?.tone !== "critical")
                  .slice(0, 1)
                  .map((entry) => ({
                    id: entry.id,
                    tone: entry.tone === "warning" ? "warning" : "notice",
                    label: entry.message,
                  })),
                ...day.quickSuggestions.slice(0, 1).map((entry) => ({
                  id: entry.id,
                  tone: "notice",
                  label: entry.reason,
                })),
              ])
              .slice(0, 3)
              .map((entry) => (
                <div
                  key={entry.id}
                  className={`sbdp-summary-bar__planner-pill sbdp-summary-bar__planner-pill--${entry.tone}`}
                >
                  {entry.label}
                </div>
              ))}
          </div>
        ) : null}

        <div className="sbdp-summary-bar__stats">
          {stats.map((stat) => (
            <div key={stat.label} className="sbdp-summary-bar__stat">
              <span>{stat.label}</span>
              <strong>{stat.value}</strong>
            </div>
          ))}
        </div>

        {hasItems ? (
          <ul className="sbdp-summary-bar__list">
            {planItems.map((item, index) => {
              const lineItem = resolveLineItem(item, index);
              const day = plan.days[item.dayIndex] || {};
              const dateLabel = formatDateLabel(day.date || "", locale);
              const amount = Number.isFinite(lineItem?.line_subtotal)
                ? lineItem.line_subtotal
                : item.totalCost ?? 0;

              return (
                <li key={item.id}>
                  <div>
                    <strong>{item.title}</strong>
                    <small>
                      {dateLabel} | {item.startTime} - {item.endTime}
                    </small>
                  </div>
                  <span>{formatPrice(amount, currency)}</span>
                </li>
              );
            })}
          </ul>
        ) : (
          <p className="sbdp-summary-bar__empty">{EMPTY_MESSAGE}</p>
        )}

        <footer className="sbdp-summary-bar__footer">
          <p>{suggestion}</p>
        </footer>
      </div>
    </aside>
  );
}

function SummaryActivitiesIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <rect x="2.5" y="3" width="11" height="2.5" rx="1.2" stroke="currentColor" strokeWidth="1.4" />
      <rect x="2.5" y="7" width="11" height="2.5" rx="1.2" stroke="currentColor" strokeWidth="1.4" />
      <rect x="2.5" y="11" width="11" height="2.5" rx="1.2" stroke="currentColor" strokeWidth="1.4" />
    </svg>
  );
}

function SummaryParticipantsIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <circle cx="6" cy="5.5" r="2.5" stroke="currentColor" strokeWidth="1.4" />
      <circle cx="11" cy="6.5" r="2" stroke="currentColor" strokeWidth="1.2" opacity="0.75" />
      <path
        d="M3 12.6c0-2 1.8-3.6 4-3.6h0.2c2.2 0 4 1.6 4 3.6"
        stroke="currentColor"
        strokeWidth="1.4"
        strokeLinecap="round"
      />
      <path
        d="M10.8 9.4c1.4 0.4 2.2 1.6 2.2 3.2"
        stroke="currentColor"
        strokeWidth="1.2"
        strokeLinecap="round"
        opacity="0.75"
      />
    </svg>
  );
}

function SummaryDurationIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <circle cx="8" cy="8" r="6" stroke="currentColor" strokeWidth="1.4" />
      <path
        d="M8 4.5V8l2.6 1.6"
        stroke="currentColor"
        strokeWidth="1.4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function ChevronIcon({ expanded }) {
  const path = expanded ? "M4 9l4-4 4 4" : "M4 7l4 4 4-4";
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 14 14"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d={path} stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function ChecklistIcon({ complete }) {
  if (complete) {
    return (
      <svg
        width="14"
        height="14"
        viewBox="0 0 14 14"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
      >
        <circle cx="7" cy="7" r="6" stroke="currentColor" strokeWidth="1.4" fill="currentColor" opacity="0.1" />
        <path d="M4.5 7.2 6.2 9 9.5 5.8" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    );
  }

  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 14 14"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <circle cx="7" cy="7" r="5.4" stroke="currentColor" strokeWidth="1.4" strokeDasharray="2 2" opacity="0.6" />
    </svg>
  );
}

function CloseSmallIcon() {
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
        strokeWidth="1.4"
        strokeLinecap="round"
      />
    </svg>
  );
}















