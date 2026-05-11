import React from "react";
import { createRoot } from "react-dom/client";

import BookingBoardApp from "./modules/BookingBoardApp";
import "./styles.css";

const ROOT_ID = "sbdp-booking-board-root";

function bootstrap() {
  const rootNode = document.getElementById(ROOT_ID);
  if (!rootNode) {
    return;
  }

  const config = window.SBDP_BOOKING_BOARD || {};
  const root = createRoot(rootNode);
  root.render(<BookingBoardApp config={config} />);
}

if (document.readyState === "complete" || document.readyState === "interactive") {
  bootstrap();
} else {
  document.addEventListener("DOMContentLoaded", bootstrap);
}
