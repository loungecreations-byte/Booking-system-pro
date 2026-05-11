import {
  calculateTotalCost,
  summarizePlan,
  toFloat,
  roundCurrency,
  formatCurrency,
  deriveSlotPricing,
} from "./pricing.js";

const helpers = {
  calculateTotalCost,
  summarizePlan,
  toFloat,
  roundCurrency,
  formatCurrency,
  deriveSlotPricing,
};

if (typeof window !== "undefined") {
  window.SBDP_DAY_PLANNER_HELPERS = {
    ...(window.SBDP_DAY_PLANNER_HELPERS || {}),
    ...helpers,
  };
}

export default helpers;
