import React, { useMemo } from "react";

import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";

export default function AutoTimeConflictHints() {
  const { state } = usePlanner();

  const conflicts = useMemo(() => detectConflicts(state.plan.items || []), [state.plan.items]);

  if (!conflicts.length) {
    return null;
  }

  return (
    <aside className="sbdp-conflict-hints" role="alert">
      <strong>Controleer je planning:</strong>
      <ul>
        {conflicts.map((conflict, index) => (
          <li key={index}>{conflict}</li>
        ))}
      </ul>
    </aside>
  );
}

function detectConflicts(items) {
  if (!Array.isArray(items) || items.length < 2) {
    return [];
  }

  const sorted = items
    .filter((item) => item.dayIndex === 0)
    .slice()
    .sort((a, b) => a.startMinutes - b.startMinutes);

  const conflicts = [];
  for (let index = 1; index < sorted.length; index++) {
    const current = sorted[index];
    const previous = sorted[index - 1];
    if (current.startMinutes < previous.endMinutes) {
      conflicts.push(
        `Overlapping activiteiten: "${previous.title}" en "${current.title}" overlappen in tijd.`
      );
    }
  }

  return conflicts;
}

