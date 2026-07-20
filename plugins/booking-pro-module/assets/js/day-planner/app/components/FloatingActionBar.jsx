import React, { useState, useEffect, useMemo } from "react";
import { createPortal } from "react-dom";
import { usePlanner } from "../../store/PlannerProvider.jsx";
import { buildPlannerCtaModel } from "../utils/planner-cta.js";

// Ultra-compact icons (16px)
const Icons = {
  cart: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="16" height="16">
      <path d="M0 1.5A.5.5 0 01.5 1H2a.5.5 0 01.485.379L2.89 3H14.5a.5.5 0 01.491.592l-1.5 8A.5.5 0 0113 12H4a.5.5 0 01-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 01-.5-.5zM5 12a2 2 0 100 4 2 2 0 000-4zm7 0a2 2 0 100 4 2 2 0 000-4z"/>
    </svg>
  ),
  mail: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="16" height="16">
      <path d="M0 4a2 2 0 012-2h12a2 2 0 012 2v8a2 2 0 01-2 2H2a2 2 0 01-2-2V4zm2-1a1 1 0 00-1 1v.217l7 4.2 7-4.2V4a1 1 0 00-1-1H2zm13 2.383l-4.758 2.855L15 11.114v-5.73zm-.034 6.878L9.271 8.82 8 9.583 6.728 8.82l-5.694 3.44A1 1 0 002 13h12a1 1 0 00.966-.739zM1 11.114l4.758-2.876L1 5.383v5.73z"/>
    </svg>
  ),
  check: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12">
      <path d="M13.854 3.646a.5.5 0 010 .708l-7 7a.5.5 0 01-.708 0l-3.5-3.5a.5.5 0 11.708-.708L6.5 10.293l6.646-6.647a.5.5 0 01.708 0z"/>
    </svg>
  ),
  warning: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12">
      <path d="M8 15A7 7 0 118 1a7 7 0 010 14zm0-9.5a.5.5 0 00-.5.5v3a.5.5 0 001 0V6a.5.5 0 00-.5-.5zm0 6a.75.75 0 100-1.5.75.75 0 000 1.5z"/>
    </svg>
  ),
  chevronUp: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="14" height="14">
      <path d="M7.646 4.646a.5.5 0 01.708 0l6 6a.5.5 0 01-.708.708L8 5.707l-5.646 5.647a.5.5 0 01-.708-.708l6-6z"/>
    </svg>
  ),
  chevronDown: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="14" height="14">
      <path d="M1.646 4.646a.5.5 0 01.708 0L8 10.293l5.646-5.647a.5.5 0 01.708.708l-6 6a.5.5 0 01-.708 0l-6-6a.5.5 0 010-.708z"/>
    </svg>
  ),
  close: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="14" height="14">
      <path d="M4.646 4.646a.5.5 0 01.708 0L8 7.293l2.646-2.647a.5.5 0 01.708.708L8.707 8l2.647 2.646a.5.5 0 01-.708.708L8 8.707l-2.646 2.647a.5.5 0 01-.708-.708L7.293 8 4.646 5.354a.5.5 0 010-.708z"/>
    </svg>
  ),
  activity: (
    <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12">
      <path d="M6 10.5a.5.5 0 01.5-.5h3a.5.5 0 010 1h-3a.5.5 0 01-.5-.5zm-2-3a.5.5 0 01.5-.5h7a.5.5 0 010 1h-7a.5.5 0 01-.5-.5zm-2-3a.5.5 0 01.5-.5h11a.5.5 0 010 1h-11a.5.5 0 01-.5-.5z"/>
    </svg>
  ),
};

function formatPrice(amount, currency = "EUR") {
  return new Intl.NumberFormat("nl-NL", {
    style: "currency",
    currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount || 0);
}

function formatTime(minutes) {
  if (!minutes && minutes !== 0) return "";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${h.toString().padStart(2, "0")}:${m.toString().padStart(2, "0")}`;
}

function isAvailabilityErrorMessage(message) {
  if (!message || typeof message !== "string") {
    return false;
  }

  const normalized = message.toLowerCase();
  return (
    normalized.includes("tijdslot") ||
    normalized.includes("beschikbaar") ||
    normalized.includes("availability")
  );
}

function firstPositiveParticipant(...values) {
  for (const value of values) {
    const parsed = Number.parseInt(value, 10);
    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
  }
  return null;
}

export default function FloatingActionBar() {
  const {
    state: { plan, config, form, summary, availabilityIssue, widgetPreferences },
    selectors,
    actions: { addToCart, submitPlan, regeneratePlan },
  } = usePlanner();
  const plannerActionState = selectors?.plannerActionState || {};
  const prefersReducedMotion =
    typeof window !== "undefined" &&
    window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  const [isExpanded, setIsExpanded] = useState(false);
  const [bookingPending, setBookingPending] = useState(false);
  const [quotePending, setQuotePending] = useState(false);

  const currency = summary?.currency || config?.currency || "EUR";
  
  const planItems = Array.isArray(plan?.items) ? plan.items : [];
  
  const hasItems = planItems.length > 0;
  const itemCount = planItems.length;

  const totalPrice = useMemo(() => {
    if (Number.isFinite(summary?.grandTotal)) return summary.grandTotal;
    if (Number.isFinite(summary?.subtotal)) return summary.subtotal;
    return null;
  }, [summary?.grandTotal, summary?.subtotal]);
  
  const participants = firstPositiveParticipant(
    selectors?.canonicalParticipants,
    form?.participants,
    config?.participants
  );
  const perPerson = Number.isFinite(summary?.participantShare) ? summary.participantShare : null;
  const totalLabel = Number.isFinite(totalPrice) ? formatPrice(totalPrice, currency) : "Prijs wordt berekend";
  const perPersonLabel = Number.isFinite(perPerson) ? `${formatPrice(perPerson, currency)}/pp` : "Prijs volgt";
  const availabilityMessage = availabilityIssue?.message || "";
  const plannerCtaModel = buildPlannerCtaModel({
    plannerActionState,
    formattedTotal: Number.isFinite(totalPrice) ? formatPrice(totalPrice, currency) : "",
    queuePending: bookingPending,
    planPending: quotePending,
  });

  useEffect(() => {
    if (availabilityMessage) {
      setIsExpanded(true);
    }
  }, [availabilityMessage]);

  // Checklist
  const checklist = useMemo(() => [
    { key: "date", label: "Datum", ok: Boolean(form?.date) },
    { key: "participants", label: "Deelnemers", ok: participants > 0 },
    { key: "activities", label: "Activiteiten", ok: hasItems },
  ], [form?.date, participants, hasItems]);

  const allComplete = Boolean(plannerActionState.requirements_met);
  const pendingCount = checklist.filter(c => !c.ok).length;

  const handleBookNow = async () => {
    if (!plannerActionState.primary_cta_enabled || bookingPending) return;
    setBookingPending(true);
    try {
      await addToCart();
    } catch (err) {
      const message = err instanceof Error ? err.message : "";
      if (!isAvailabilityErrorMessage(message) && typeof console !== "undefined") {
        console.error("Book error:", err);
      }
    } finally {
      setBookingPending(false);
    }
  };

  const handleRequestQuote = async () => {
    if (!plannerActionState.secondary_quote_enabled || quotePending) return;
    setQuotePending(true);
    try {
      await submitPlan();
    } catch (err) {
      console.error("Quote error:", err);
    } finally {
      setQuotePending(false);
    }
  };

  const handlePrimaryAction = () => {
    if (plannerCtaModel.primary.key === "checkout") {
      return handleBookNow();
    }
    if (plannerCtaModel.primary.key === "quote") {
      return handleRequestQuote();
    }
    setIsExpanded(true);
    return undefined;
  };

  const handleSecondaryAction = () => {
    if (plannerCtaModel.secondary?.key === "quote") {
      return handleRequestQuote();
    }
    setIsExpanded(true);
    return undefined;
  };

  const primaryIcon = plannerCtaModel.primary.key === "quote" ? Icons.mail : Icons.cart;
  const secondaryIcon = plannerCtaModel.secondary?.key === "quote" ? Icons.mail : Icons.warning;

  const handleReplan = () => {
    regeneratePlan({
      visitDate: form?.date,
      count: participants,
      audience: widgetPreferences?.audience || "vrienden",
      vibe: widgetPreferences?.vibe || "verrassend",
      duration: widgetPreferences?.duration || "hele-dag",
    });
  };

  // FAB content - will be rendered via Portal to document.body
  const fabContent = (
    <div
      className={`sbdp-fab ${prefersReducedMotion ? "sbdp-fab--reduced-motion" : ""}`.trim()}
      data-testid="sbdp-fab"
      dir="ltr"
    >
      {!isExpanded ? (
        <div className="sbdp-fab__collapsed">
          <button
            type="button"
            className={`sbdp-fab__pill ${allComplete ? "is-complete" : "is-incomplete"}`.trim()}
            onClick={() => setIsExpanded(true)}
          >
            <span className="sbdp-fab__pill-group">
              {Icons.cart}
              <span className="sbdp-fab__badge">{itemCount}</span>
              <span>{totalLabel}</span>
              {pendingCount > 0 && (
                <span className="sbdp-fab__badge sbdp-fab__badge--warning">{pendingCount}</span>
              )}
            </span>
            <span className="sbdp-fab__pill-chevron">{Icons.chevronUp}</span>
          </button>
          <div className="sbdp-fab__collapsed-actions">
            <button
              type="button"
              className={`ui-btn ui-btn--${plannerCtaModel.primary.variant} ui-btn--planner ui-btn--full sbdp-fab__action sbdp-fab__action--compact ${!plannerCtaModel.primary.enabled ? "is-disabled" : ""}`.trim()}
              onClick={handlePrimaryAction}
              disabled={!plannerCtaModel.primary.enabled}
              aria-busy={plannerCtaModel.primary.busy ? "true" : "false"}
              aria-label={plannerCtaModel.primary.ariaLabel}
              data-planner-action={plannerCtaModel.primary.key}
            >
              {primaryIcon}
              <span>{plannerCtaModel.primary.label}</span>
            </button>
            {plannerCtaModel.secondary ? (
              <button
                type="button"
                className={`ui-btn ui-btn--${plannerCtaModel.secondary.variant} ui-btn--full sbdp-fab__action sbdp-fab__action--compact ${!plannerCtaModel.secondary.enabled ? "is-disabled" : ""}`.trim()}
                onClick={handleSecondaryAction}
                disabled={!plannerCtaModel.secondary.enabled}
                aria-busy={plannerCtaModel.secondary.busy ? "true" : "false"}
                aria-label={plannerCtaModel.secondary.ariaLabel}
                data-planner-action={plannerCtaModel.secondary.key}
              >
                {secondaryIcon}
                <span>{plannerCtaModel.secondary.label}</span>
              </button>
            ) : null}
          </div>
        </div>
      ) : (
        <div className="sbdp-fab__card ui-panel ui-panel--raised">
          {/* Header */}
          <div className="sbdp-fab__header">
            <div className="sbdp-fab__header-copy">
              <div className="sbdp-fab__title">{itemCount} activiteit{itemCount !== 1 ? "en" : ""}</div>
              <div className="sbdp-fab__subtitle">
                {participants || "—"} pers · {perPersonLabel}
              </div>
            </div>
            <button
              type="button"
              className="ui-btn ui-btn--ghost ui-btn--icon sbdp-fab__close"
              onClick={() => setIsExpanded(false)}
            >
              {Icons.close}
            </button>
          </div>

          {/* Body - Items list */}
          <div className="sbdp-fab__body">
            {planItems.length === 0 ? (
              <div className="sbdp-fab__empty">
                Nog geen activiteiten toegevoegd
              </div>
            ) : (
              planItems.slice(0, 5).map((item, idx) => (
                <div key={item.id || idx} className="sbdp-fab__item">
                  <div className="sbdp-fab__item-name">
                    {Icons.activity}
                    <span className="sbdp-fab__item-name-text">{item.productName || item.name || "Activiteit"}</span>
                    {item.startMinutes !== undefined && (
                      <span className="sbdp-fab__item-time">{formatTime(item.startMinutes)}</span>
                    )}
                  </div>
                  <span className="sbdp-fab__item-price">{formatPrice(item.totalCost || item.price, currency)}</span>
                </div>
              ))
            )}
            {planItems.length > 5 && (
              <div className="sbdp-fab__more">
                +{planItems.length - 5} meer...
              </div>
            )}

            {/* Checklist */}
            {plannerActionState.blocking_reason_message && (
              <div className="sbdp-fab__availability">
                <strong>{plannerActionState.status_label || "Planner status"}</strong>
                <span>{plannerActionState.blocking_reason_message}</span>
              </div>
            )}

            {!allComplete && (
              <div className="sbdp-fab__checklist">
                {checklist.map(c => (
                  <div key={c.key} className="sbdp-fab__checklist-row">
                    <span className={`sbdp-fab__check-icon ${c.ok ? "is-ok" : "is-warning"}`.trim()}>
                      {c.ok ? Icons.check : Icons.warning}
                    </span>
                    <span className={`sbdp-fab__check-label ${c.ok ? "is-ok" : "is-warning"}`.trim()}>
                      {c.label}
                    </span>
                  </div>
                ))}
              </div>
            )}

            {availabilityMessage ? (
              <div className="sbdp-fab__availability">
                <strong>Tijdslot niet meer beschikbaar</strong>
                <span>{availabilityMessage}</span>
                <button type="button" className="ui-btn ui-btn--ghost ui-btn--sm sbdp-fab__availability-btn" onClick={handleReplan}>
                  Herplan nu
                </button>
              </div>
            ) : null}
          </div>

          {/* Footer - Total + Actions */}
          <div className="sbdp-fab__footer">
            <div className="sbdp-fab__total-row">
              <span className="sbdp-fab__total-label">Totaal</span>
              <div>
                <span className="sbdp-fab__total-value">{totalLabel}</span>
                <span className="sbdp-fab__per-person">({perPersonLabel})</span>
              </div>
            </div>
            <button
              type="button"
              className={`ui-btn ui-btn--${plannerCtaModel.primary.variant} ui-btn--planner ui-btn--full sbdp-fab__action ${!plannerCtaModel.primary.enabled ? "is-disabled" : ""}`.trim()}
              onClick={handlePrimaryAction}
              disabled={!plannerCtaModel.primary.enabled}
              aria-busy={plannerCtaModel.primary.busy ? "true" : "false"}
              aria-label={plannerCtaModel.primary.ariaLabel}
              data-planner-action={plannerCtaModel.primary.key}
            >
              {primaryIcon}
              <span>{plannerCtaModel.primary.label}</span>
            </button>
            {plannerCtaModel.secondary ? (
              <button
                type="button"
                className={`ui-btn ui-btn--${plannerCtaModel.secondary.variant} ui-btn--full sbdp-fab__action ${!plannerCtaModel.secondary.enabled ? "is-disabled" : ""}`.trim()}
                onClick={handleSecondaryAction}
                disabled={!plannerCtaModel.secondary.enabled}
                aria-busy={plannerCtaModel.secondary.busy ? "true" : "false"}
                aria-label={plannerCtaModel.secondary.ariaLabel}
                data-planner-action={plannerCtaModel.secondary.key}
              >
                {secondaryIcon}
                <span>{plannerCtaModel.secondary.label}</span>
              </button>
            ) : null}
          </div>
        </div>
      )}
    </div>
  );

  // Render via Portal to document.body to escape any parent container CSS issues
  return createPortal(fabContent, document.body);
}
