import React from "react";
import { createRoot } from "react-dom/client";
import BookingsOverviewApp from "./modules/BookingsOverviewApp";
import "./styles.css";

const ROOT_ID = "sbdp-bookings-overview-root";

function bootstrap() {
  const container = document.getElementById(ROOT_ID);
  if (!container) {
    return;
  }

  const config = window.SBDP_BOOKINGS_OVERVIEW || {};

  const root = createRoot(container);
  root.render(<BookingsOverviewApp config={config} />);
}

if (document.readyState === "complete" || document.readyState === "interactive") {
  bootstrap();
} else {
  document.addEventListener("DOMContentLoaded", bootstrap);
}

