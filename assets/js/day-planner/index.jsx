import React from "react";
import { createRoot } from "react-dom/client";

import PlannerLayout from "./modules/PlannerLayout";
import PlannerProvider from "./store/PlannerProvider";

const ROOT_ID = "sbdp-day-planner-root";

function bootstrap() {
  const node = document.getElementById(ROOT_ID);
  if (!node) {
    return;
  }

  const config = window.SBDP_DAY_PLANNER || {};
  const root = createRoot(node);
  root.render(
    <PlannerProvider bootConfig={config}>
      <PlannerLayout />
    </PlannerProvider>
  );
}

if (document.readyState === "complete" || document.readyState === "interactive") {
  bootstrap();
} else {
  document.addEventListener("DOMContentLoaded", bootstrap);
}
