import React from "react";

import { usePlanner } from "../day-planner/store/PlannerProvider.jsx";
import ActivityListMobile from "./components/ActivityListMobile.jsx";
import AutoTimeConflictHints from "./components/AutoTimeConflictHints.jsx";
import DateParticipantForm from "./components/DateParticipantForm.jsx";
import DayTimelineList from "./components/DayTimelineList.jsx";
import EmptyStatePlaceholder from "./components/EmptyStatePlaceholder.jsx";
import PlannerStateSync from "./components/PlannerStateSync.jsx";
import PlanSummaryBox from "./components/PlanSummaryBox.jsx";
import StickyActionBar from "./components/StickyActionBar.jsx";
import useProgressiveSteps from "./hooks/useProgressiveSteps.js";

export default function MobilePlannerApp() {
  const { state } = usePlanner();

  const hasPlan = state.plan.days.length > 0;
  const hasItems = state.plan.items.length > 0;

  const steps = useProgressiveSteps({ hasPlan, hasItems });

  return (
    <div className="sbdp-mobile-planner">
      <DateParticipantForm visible={steps.isVisible("form")} />

      {steps.isVisible("activities") ? <ActivityListMobile /> : null}

      {steps.isVisible("timeline") ? (
        <section className="sbdp-mobile-section">
          <AutoTimeConflictHints />
          <DayTimelineList />
          {hasPlan && !hasItems ? (
            <EmptyStatePlaceholder message="Nog geen planning — voeg een activiteit toe om te starten." />
          ) : null}
        </section>
      ) : null}

      {steps.isVisible("summary") ? <PlanSummaryBox /> : null}

      <PlannerStateSync autoSaveSeconds={15} />

      {steps.isVisible("actions") ? <StickyActionBar /> : null}
    </div>
  );
}

