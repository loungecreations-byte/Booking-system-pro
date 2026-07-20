import React, { useEffect } from "react";
import PropTypes from "prop-types";

import { usePlanner } from "../store/PlannerProvider.jsx";
import { UserProfileProvider, useUserProfile } from "./context/UserProfileContext.jsx";
import InfoStep from "./components/InfoStep.jsx";
import LayoutStep from "./components/LayoutStep.jsx";
import PlannerTelemetryProvider from "./components/PlannerTelemetryProvider.jsx";
import Toast from "./components/Toast.jsx";
import { getLocalDateIso } from "./utils/time.js";

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

function buildPendingPrefillPreview() {
  if (typeof window === "undefined") {
    return { isPending: false, labels: [], participants: null };
  }

  const queue = readPendingPrefillEntries();
  const bootPrefill = window.SBDP_DAY_PLANNER?.prefill || null;
  const sourceEntry = queue[0] || bootPrefill || null;

  if (!sourceEntry || typeof sourceEntry !== "object") {
    return { isPending: false, labels: [], participants: null };
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

  pushLabel(sourceEntry.title || sourceEntry.name || "");

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
    isPending:
      Boolean(sourceEntry.product_id || sourceEntry.productId || sourceEntry.id) ||
      labels.length > 0,
    labels,
    participants: toPositiveInt(sourceEntry?.participants ?? sourceEntry?.people),
  };
}

function PlannerShell() {
  const {
    state: { plan, form, loading, error },
    actions: { setFormField, startPlanning },
  } = usePlanner();
  const {
    actions: { setPlannedItems },
  } = useUserProfile();

  const planItems = Array.isArray(plan?.items) ? plan.items : [];
  const hasPlan = Array.isArray(plan?.days) && plan.days.length > 0;
  const canRenderLayout = hasPlan || (!loading?.config && Boolean(form?.date));
  const pendingPrefillPreview = buildPendingPrefillPreview();
  const showPendingShellState =
    !hasPlan &&
    planItems.length === 0 &&
    pendingPrefillPreview.isPending;
  const bootstrapPlanning = (scrollToResults = false) => {
    const nextDate = form?.date || getLocalDateIso();

    if (!form?.date) {
      setFormField("date", nextDate);
    }

    startPlanning();

    if (scrollToResults && typeof window !== "undefined") {
      window.setTimeout(() => {
        document
          .querySelector(".sbdp-day-planner__results")
          ?.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 120);
    }
  };

  useEffect(() => {
    if (typeof document === "undefined") {
      return;
    }

    const pageTransition = document.querySelector("e-page-transition");
    if (!(pageTransition instanceof HTMLElement)) {
      return;
    }

    pageTransition.setAttribute("disabled", "");
    pageTransition.classList.remove("e-page-transition--entering", "e-page-transition--exiting");
    pageTransition.classList.add("e-page-transition--entered");
    pageTransition.style.display = "none";
    pageTransition.style.pointerEvents = "none";
  }, []);

  useEffect(() => {
    const nextItems = planItems.map((item) => ({
      id: item.productId,
      title: item.title,
      image: item.image || "",
      location: item.location || "Den Bosch",
    }));
    setPlannedItems(nextItems);
  }, [planItems, setPlannedItems]);

  return (
    <PlannerTelemetryProvider activeView="planner">
      <div className="sbdp-day-planner">
        <h1 className="sbdp-day-planner__screen-title">Plan je dag in Den Bosch</h1>
        {error ? <div className="sbdp-day-planner__error">{error}</div> : null}

        <InfoStep />

        {canRenderLayout ? <LayoutStep /> : null}

        {!canRenderLayout ? (
          showPendingShellState ? (
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
          ) : (
            <section className="sbdp-planner-state-card sbdp-planner-state-card--empty" aria-label="Lege dagplanning">
              <div className="sbdp-planner-state-card__body">
                <p className="sbdp-planner-state-card__eyebrow">Dagplanning</p>
                <h3>Nog niets in je dagplanning</h3>
                <p>
                  Kies een datum en voeg activiteiten toe om jullie dag samen te stellen.
                </p>
              </div>
              <div className="sbdp-planner-state-card__actions">
                <button
                  type="button"
                  className="ui-btn ui-btn--secondary"
                  onClick={() => bootstrapPlanning(true)}
                >
                  Bekijk activiteiten
                </button>
                <button
                  type="button"
                  className="ui-btn ui-btn--primary ui-btn--planner"
                  onClick={() => bootstrapPlanning(false)}
                >
                  Start met plannen
                </button>
              </div>
            </section>
          )
        ) : null}

        {loading?.products && canRenderLayout ? (
          <div className="sbdp-day-planner__notice" role="status">
            Activiteiten laden...
          </div>
        ) : null}

        <Toast />
      </div>
    </PlannerTelemetryProvider>
  );
}

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch() {}

  render() {
    if (this.state.hasError) {
      return (
        <div className="sbdp-day-planner">
          <div className="sbdp-day-planner__empty sbdp-day-planner__empty--error">
            <p>Er is een fout opgetreden bij het laden van de planner.</p>
            <button
              type="button"
              className="ui-btn ui-btn--secondary"
              onClick={() => window.location.reload()}
            >
              Pagina herladen
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}

ErrorBoundary.propTypes = {
  children: PropTypes.node.isRequired,
};

export function PlannerSkeleton() {
  return (
    <div className="sbdp-day-planner" aria-busy="true" aria-live="polite">
      <section className="sbdp-planner-state-card sbdp-planner-state-card--pending" role="status">
        <div className="sbdp-planner-state-card__body">
          <p className="sbdp-planner-state-card__eyebrow">Planner laden</p>
          <h3>Je dagplanner wordt klaargezet...</h3>
          <p>We laden de activiteiten, tijden en planning zodat je direct verder kunt.</p>
        </div>
      </section>
    </div>
  );
}

export default function PlannerApp() {
  return (
    <ErrorBoundary>
      <UserProfileProvider>
        <PlannerShell />
      </UserProfileProvider>
    </ErrorBoundary>
  );
}
