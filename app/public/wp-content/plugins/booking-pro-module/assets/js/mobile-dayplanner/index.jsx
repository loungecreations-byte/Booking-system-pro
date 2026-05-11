import React from "react";
import { createRoot } from "react-dom/client";

import MobilePlannerApp from "./MobilePlannerApp.jsx";
import { PlannerProvider } from "../day-planner/store/PlannerProvider.jsx";

const ROOT_ID = "sbdp-mobile-day-planner-root";
const MOBILE_QUERY = "(max-width: 767px)";

// Global lock to prevent simultaneous renders
if (!window.__SBDP_RENDER_LOCK) {
  window.__SBDP_RENDER_LOCK = { desktop: false, mobile: false };
}

let mobileRoot = null;
let mobileRootNode = null;
let hasRendered = false;
let isBootstrapping = false;

function isMobileViewport() {
  if (typeof window.matchMedia !== "function") {
    return typeof window.orientation !== "undefined";
  }

  return window.matchMedia(MOBILE_QUERY).matches;
}

function ensureRoot(node) {
  if (mobileRoot && mobileRootNode === node) {
    return mobileRoot;
  }

  if (mobileRoot && mobileRootNode && mobileRootNode !== node) {
    try {
      mobileRoot.unmount();
    } catch (e) {
      console.warn('Error unmounting mobile root:', e);
    }
    mobileRoot = null;
    mobileRootNode = null;
    hasRendered = false;
  }

  mobileRoot = createRoot(node);
  mobileRootNode = node;
  return mobileRoot;
}

function bootstrap() {
  // Prevent concurrent bootstrap calls
  if (isBootstrapping) {
    console.log('🛑 Mobile bootstrap already in progress, skipping...');
    return;
  }

  const node = document.getElementById(ROOT_ID);
  if (!node || !isMobileViewport()) {
    return;
  }

  // Prevent multiple renders
  if (hasRendered || window.__SBDP_RENDER_LOCK.mobile) {
    console.log('🛑 Mobile bootstrap called but already rendered, skipping...');
    return;
  }

  // Check if desktop is rendering
  if (window.__SBDP_RENDER_LOCK.desktop) {
    console.log('🛑 Desktop planner is active, skipping mobile...');
    return;
  }

  isBootstrapping = true;
  window.__SBDP_RENDER_LOCK.mobile = true;

  try {
    const config = window.SBDP_DAY_PLANNER || {};
    const root = ensureRoot(node);
    
    // Ensure the node is clean before rendering
    if (node.children.length > 0 && !node.hasAttribute('data-react-root')) {
      console.warn('Clearing pre-existing content from mobile root node');
      node.innerHTML = '';
    }
    
    node.removeAttribute("aria-hidden");
    
    console.log('🚀 Rendering PlannerProvider (mobile)');
    root.render(
      <PlannerProvider bootConfig={config}>
        <MobilePlannerApp />
      </PlannerProvider>
    );
    
    node.setAttribute('data-react-root', 'mobile');
    hasRendered = true;
  } catch (error) {
    console.error('Error bootstrapping mobile planner:', error);
    window.__SBDP_RENDER_LOCK.mobile = false;
  } finally {
    isBootstrapping = false;
  }
}

if (document.readyState === "complete" || document.readyState === "interactive") {
  bootstrap();
} else {
  document.addEventListener("DOMContentLoaded", bootstrap);
}

if (typeof window.matchMedia === "function") {
  const mediaQuery = window.matchMedia(MOBILE_QUERY);
  const handleChange = () => {
    if (mediaQuery.matches) {
      bootstrap();
    }
  };

  if (typeof mediaQuery.addEventListener === "function") {
    mediaQuery.addEventListener("change", handleChange);
  } else if (typeof mediaQuery.addListener === "function") {
    mediaQuery.addListener(handleChange);
  }
}
