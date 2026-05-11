import React, { useEffect } from "react";

import { usePlanner } from "../../store/PlannerProvider.jsx";

const TOAST_TIMEOUT = 4000;

export default function Toast() {
  const {
    state: { toast },
    actions: { clearToast },
  } = usePlanner();

  useEffect(() => {
    if (!toast) {
      return undefined;
    }

    const timer = setTimeout(() => {
      clearToast();
    }, TOAST_TIMEOUT);

    return () => clearTimeout(timer);
  }, [toast, clearToast]);

  if (!toast) {
    return null;
  }

  return (
    <div className="sbdp-toast ui-motion-overlay ui-motion-fade" role="status">
      {toast}
      <button type="button" onClick={clearToast} aria-label="Sluiten">
        ×
      </button>
    </div>
  );
}
