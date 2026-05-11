import { createApp } from "vue";
import FinanceLayout from "./FinanceLayout.js";
import "./styles.css";

const mountNode = document.getElementById("sbdp-finance-dashboard-root");
const config = window.SBDP_FINANCE_DASHBOARD ?? {};

if (mountNode) {
  const app = createApp(FinanceLayout, { config });
  app.mount(mountNode);
}
