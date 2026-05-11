import React from "react";
import { createRoot } from "react-dom/client";

import PlannerApp from "./app/App";
import PlannerProvider from "./store/PlannerProvider";

const ROOT_ID = "sbdp-day-planner-root";
let plannerRoot = null;
let plannerRootNode = null;

function ensureRoot(node) {
  if (plannerRoot && plannerRootNode === node) {
    return plannerRoot;
  }

  if (plannerRoot && plannerRootNode && plannerRootNode !== node) {
    try {
      plannerRoot.unmount();
    } catch (error) {
      // no-op: a stale root should never block remounting
    }
    plannerRoot = null;
    plannerRootNode = null;
  }

  plannerRoot = createRoot(node);
  plannerRootNode = node;

  return plannerRoot;
}

function bootstrap() {
  const node = document.getElementById(ROOT_ID);
  if (!node) {
    return;
  }

  const config = window.SBDP_DAY_PLANNER || {};
  const root = ensureRoot(node);

  if (node.children.length > 0 && !node.hasAttribute("data-react-root")) {
    node.innerHTML = "";
  }

  node.removeAttribute("aria-hidden");

  root.render(
    <PlannerProvider bootConfig={config}>
      <PlannerApp />
    </PlannerProvider>
  );

  node.setAttribute("data-react-root", "desktop");
}

if (document.readyState === "complete" || document.readyState === "interactive") {
  bootstrap();
} else {
  document.addEventListener("DOMContentLoaded", bootstrap);
}
