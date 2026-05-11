import React, { useMemo } from "react";

import { itemConflicts } from "../../day-planner/app/utils/schedule";
import { minutesToTime, timeToMinutes } from "../../day-planner/app/utils/time";
import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";

const DAY_INDEX = 0;

export default function DayTimelineList() {
  const {
    state,
    actions: { updateActivity, removeActivity, showToast },
  } = usePlanner();

  const step = Math.max(5, parseInt(state.config?.time_step_minutes, 10) || 30);
  const openHours = state.config?.open_hours || { start: "09:00", end: "24:00" };
  const productsById = useMemo(
    () =>
      new Map(
        (state.products || []).map((product) => [product.id, product])
      ),
    [state.products]
  );

  const items = useMemo(() => {
    return (state.plan.items || [])
      .filter((item) => item.dayIndex === DAY_INDEX)
      .slice()
      .sort((a, b) => a.startMinutes - b.startMinutes);
  }, [state.plan.items]);

  const handleAdjust = (item, direction) => {
    if (item.locked) {
      showToast("Dit vaste tijdslot kan niet worden aangepast.");
      return;
    }

    const delta = direction === "earlier" ? -step : step;
    const nextStartMinutes = Math.max(
      timeToMinutes(openHours.start || "09:00"),
      item.startMinutes + delta
    );
    const nextEndMinutes = nextStartMinutes + (item.durationMinutes || step);

    const dayEnd = openHours.end === "24:00" ? 24 * 60 : timeToMinutes(openHours.end || "24:00");
    if (nextEndMinutes > dayEnd) {
      showToast("Dit tijdstip valt buiten de openingstijden.");
      return;
    }

    if (itemConflicts(state.plan.items, DAY_INDEX, nextStartMinutes, nextEndMinutes, item.id)) {
      showToast("Deze wijziging zorgt voor overlapping met een andere activiteit.");
      return;
    }

    updateActivity(item.id, {
      startTime: minutesToTime(nextStartMinutes),
    });
  };

  return (
    <div className="sbdp-mobile-timeline">
      {items.length === 0 ? (
        <p className="sbdp-mobile-timeline__empty">
          Nog geen activiteiten gepland op deze dag.
        </p>
      ) : (
        <ul className="sbdp-mobile-timeline__list">
          {items.map((item) => {
            const product = productsById.get(item.productId);
            const title = product?.title || product?.name || item.title;

            return (
              <li key={item.id} className="sbdp-mobile-timeline__item">
                <div className="sbdp-mobile-timeline__time">
                  <span>{item.startTime}</span>
                  <span aria-hidden="true">–</span>
                  <span>{item.endTime}</span>
                </div>
                <div className="sbdp-mobile-timeline__details">
                  <h4>{title}</h4>
                  <p>
                    {item.participants} deelnemer{item.participants === 1 ? "" : "s"}
                  </p>
                </div>
                <div className="sbdp-mobile-timeline__actions">
                  <button
                    type="button"
                    className="sbdp-icon-button"
                    onClick={() => handleAdjust(item, "earlier")}
                    disabled={item.locked}
                    aria-label="Verplaats eerder"
                  >
                    ↑
                  </button>
                  <button
                    type="button"
                    className="sbdp-icon-button"
                    onClick={() => handleAdjust(item, "later")}
                    disabled={item.locked}
                    aria-label="Verplaats later"
                  >
                    ↓
                  </button>
                  {item.locked ? (
                    <span className="sbdp-tag sbdp-tag--locked">Vast</span>
                  ) : (
                    <button
                      type="button"
                      className="sbdp-icon-button sbdp-icon-button--danger"
                      onClick={() => removeActivity(item.id)}
                      aria-label="Verwijder activiteit"
                    >
                      ✕
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

