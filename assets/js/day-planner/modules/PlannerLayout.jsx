import React, { useCallback, useMemo, useState } from "react";

import ActivityFilterPanel from "./ActivityFilterPanel";
import ActivityList from "./ActivityList";
import CalendarBoard from "./CalendarBoard";
import TimelineCompact from "./TimelineCompact";
import MapPanel from "./MapPanel";
import PriceSummary from "./PriceSummary";
import ShareBar from "./ShareBar";
import ActionButtons from "./ActionButtons";
import AddCustomActivityModal from "./AddCustomActivityModal";
import NotesPanel from "./NotesPanel";
import AIDialog from "./AIDialog";
import ConflictWarnings from "./ConflictWarnings";
import BusyIndicator from "./BusyIndicator";
import { usePlannerActions, usePlannerState } from "../store/PlannerProvider";

const resolveCurrency = (plan) => {
  if (!plan || !plan.totals) {
    return "EUR";
  }

  return plan.totals.currency || plan.currency || "EUR";
};

function PlannerLayout() {
  const plannerState = usePlannerState();
  const actions = usePlannerActions();
  const [showModal, setShowModal] = useState(false);

  const days = useMemo(() => plannerState.plan.days || [], [plannerState.plan.days]);
  const currency = useMemo(() => resolveCurrency(plannerState.plan), [plannerState.plan]);

  const handleFiltersChange = useCallback(
    (filters) => {
      actions.setFilters(filters);
    },
    [actions]
  );

  const handleNotesChange = useCallback(
    (notes) => {
      actions.updatePlan((prev) => ({
        ...prev,
        notes,
      }));
    },
    [actions]
  );

  const handleAddActivity = useCallback(
    (activity) => {
      if (!activity) {
        return;
      }

      actions.addActivityToDay(0, activity);
    },
    [actions]
  );

  const handleShare = useCallback(async () => {
    const result = await actions.sharePlan();
    if (result && result.url) {
      const clipboard = window?.navigator?.clipboard;
      clipboard?.writeText?.(result.url).catch(() => {});
    }
  }, [actions]);

  const handleBook = useCallback(async () => {
    await actions.queueBooking();
  }, [actions]);

  const handleRequestQuote = useCallback(async () => {
    await actions.savePlan();
  }, [actions]);

  const handleExportPdf = useCallback(async () => {
    await actions.exportPlan("pdf");
  }, [actions]);

  const handleExportIcs = useCallback(async () => {
    await actions.exportPlan("ics");
  }, [actions]);

  const handleDismissNotice = useCallback(() => {
    actions.clearNotice();
  }, [actions]);

  return (
    <div className="sbdp-day-planner">
      <BusyIndicator busy={plannerState.busy} label="Planner bijwerken…" />
      <header className="sbdp-day-planner__hero">
        <h2>Plan je dag</h2>
        <p>
          Selecteer activiteiten, wijs ze aan een tijdvak toe en houd deelnemers en notities bij. Wijzigingen worden
          automatisch opgeslagen zodat je nooit werk verliest.
        </p>
      </header>
      {plannerState.error && (
        <div className="sbdp-day-planner__error" role="alert">
          {plannerState.error}
        </div>
      )}
      {plannerState.notice && (
        <div
          className={`sbdp-day-planner__notice sbdp-day-planner__notice--${plannerState.notice.type || "info"}`}
          role="status"
        >
          <span>{plannerState.notice.message}</span>
          <button type="button" onClick={handleDismissNotice} aria-label="Melding sluiten">
            &times;
          </button>
        </div>
      )}
      <div className="sbdp-day-planner__columns">
        <div className="sbdp-day-planner__column sbdp-day-planner__column--left">
          <ActivityFilterPanel filters={plannerState.filters} onChange={handleFiltersChange} />
          <ActivityList
            activities={plannerState.activities}
            onAdd={handleAddActivity}
            loading={plannerState.status.loadingActivities}
          />
          <NotesPanel notes={plannerState.plan.notes || ""} onChange={handleNotesChange} />
        </div>
        <div className="sbdp-day-planner__column sbdp-day-planner__column--main">
          <CalendarBoard
            days={days}
            onAddActivity={actions.addActivityToDay}
            onMoveSlot={actions.moveSlot}
            onUpdateSlot={actions.updateSlot}
            onRemoveSlot={actions.removeSlot}
          />
          <TimelineCompact days={days} />
          <ConflictWarnings conflicts={plannerState.plan.conflicts || []} />
        </div>
        <aside className="sbdp-day-planner__column sbdp-day-planner__column--right">
          <MapPanel points={plannerState.plan.locations || []} />
          <PriceSummary totals={plannerState.plan.totals || {}} currency={currency} />
          <ShareBar shareUrl={plannerState.share.url || ""} onShare={handleShare} />
          <ActionButtons
            onBookNow={handleBook}
            onRequestQuote={handleRequestQuote}
            onShare={handleShare}
            onExportPdf={handleExportPdf}
            onExportIcs={handleExportIcs}
          />
          <button
            type="button"
            className="button button-secondary sbdp-day-planner__custom-activity"
            onClick={() => setShowModal(true)}
          >
            Eigen activiteit toevoegen
          </button>
        </aside>
      </div>
      <AddCustomActivityModal isOpen={showModal} onClose={() => setShowModal(false)} />
      <AIDialog suggestions={plannerState.aiSuggestions} onClose={actions.clearAiSuggestions} />
    </div>
  );
}

export default PlannerLayout;

