import React, { useEffect, useRef } from "react";
import PropTypes from "prop-types";

import { usePlanner } from "../../store/PlannerProvider.jsx";
import { emitPlannerEvent, setPlannerTelemetryContextProvider } from "../utils/telemetry.js";

export default function PlannerTelemetryProvider({ activeView, children }) {
  const {
    state: { step, plan, form, summary },
  } = usePlanner();

  const previousStepRef = useRef(step);
  const previousViewRef = useRef(activeView);
  const planStartTrackedRef = useRef(false);
  const dropoffSentRef = useRef(false);
  const conversionIntentRef = useRef(false);

  const planItems = Array.isArray(plan?.items) ? plan.items : [];
  const hasPlan = Array.isArray(plan?.days) && plan.days.length > 0;
  const participantCount =
    Number.parseInt(form?.participants, 10) ||
    (Number.isFinite(plan?.participants) ? plan.participants : 0) ||
    0;
  const currency = summary?.currency || "EUR";
  const total = Number.isFinite(summary?.grandTotal)
    ? summary.grandTotal
    : Number.isFinite(summary?.subtotal)
    ? summary.subtotal
    : 0;

  useEffect(() => {
    setPlannerTelemetryContextProvider(() => ({
      planner_step: step === "review" ? "layout" : step,
      planner_view: activeView,
      planner_items: planItems.length,
      planner_participants: participantCount,
      planner_total: total,
      planner_currency: currency,
      planner_has_plan: hasPlan ? 1 : 0,
      planner_path:
        typeof window !== "undefined" && window.location ? window.location.pathname : "",
      planner_viewport_width: typeof window !== "undefined" ? window.innerWidth : null,
    }));

    return () => {
      setPlannerTelemetryContextProvider(null);
    };
  }, [step, activeView, planItems.length, participantCount, total, currency, hasPlan]);

  useEffect(() => {
    const currentStep = step === "review" ? "layout" : step;
    if (previousStepRef.current !== currentStep) {
      emitPlannerEvent("sbdp:planner/step-change", {
        previous_step: previousStepRef.current,
        next_step: currentStep,
      });
      previousStepRef.current = currentStep;
    }
  }, [step]);

  useEffect(() => {
    if (previousViewRef.current !== activeView) {
      emitPlannerEvent("sbdp:planner/view-change", {
        previous_view: previousViewRef.current,
        next_view: activeView,
      });
      previousViewRef.current = activeView;
    }
  }, [activeView]);

  useEffect(() => {
    if (hasPlan && !planStartTrackedRef.current) {
      planStartTrackedRef.current = true;
      emitPlannerEvent("sbdp:planner/plan-started", {
        items_count: planItems.length,
        participants: participantCount,
      });
    }

    if (!hasPlan) {
      planStartTrackedRef.current = false;
      dropoffSentRef.current = false;
      conversionIntentRef.current = false;
    }
  }, [hasPlan, planItems.length, participantCount]);

  useEffect(() => {
    const actionListener = (event) => {
      const detail = event?.detail || {};
      const action = String(detail.action || "");
      const status = String(detail.status || "");

      if ((action === "queue" || action === "request-quote") && status !== "") {
        conversionIntentRef.current = true;
      }
    };

    window.addEventListener("sbdp:planner/action", actionListener);
    return () => {
      window.removeEventListener("sbdp:planner/action", actionListener);
    };
  }, []);

  useEffect(() => {
    const trackDropoff = (reason) => {
      if (dropoffSentRef.current || conversionIntentRef.current) {
        return;
      }

      if (!hasPlan && planItems.length === 0) {
        return;
      }

      dropoffSentRef.current = true;
      emitPlannerEvent("sbdp:planner/dropoff", {
        reason,
        items_count: planItems.length,
        participants: participantCount,
        total_value: total,
      });
    };

    const handlePageHide = () => trackDropoff("pagehide");
    const handleBeforeUnload = () => trackDropoff("beforeunload");
    const handleVisibility = () => {
      if (document.visibilityState === "hidden") {
        trackDropoff("visibility_hidden");
      }
    };

    window.addEventListener("pagehide", handlePageHide);
    window.addEventListener("beforeunload", handleBeforeUnload);
    document.addEventListener("visibilitychange", handleVisibility);

    return () => {
      window.removeEventListener("pagehide", handlePageHide);
      window.removeEventListener("beforeunload", handleBeforeUnload);
      document.removeEventListener("visibilitychange", handleVisibility);
    };
  }, [hasPlan, planItems.length, participantCount, total]);

  return children;
}

PlannerTelemetryProvider.propTypes = {
  activeView: PropTypes.string,
  children: PropTypes.node.isRequired,
};

PlannerTelemetryProvider.defaultProps = {
  activeView: "planner",
};
