// Central hub for shared booking logic (pricing, product parsing, activity helpers).
// Keeps planner + widget in sync and avoids duplicate implementations.
import {
  calculateTotalCost,
  deriveSlotPricing,
  formatPrice,
  getPricePerPerson,
  getSlotPricePerPerson,
  computeSlotPricing,
  roundCurrency,
  summarizePlan,
  toFloat,
} from "../app/utils/price.js";
import {
  getDurationMinutes,
  getEnvironmentTag,
  normalizeNumeric,
} from "../app/utils/products.js";
import {
  buildDays,
  createPlannedItem,
  updatePlannedItem,
  itemConflicts,
  findNextAvailableTime,
} from "../app/utils/schedule.js";

export const pricing = {
  calculateTotalCost,
  deriveSlotPricing,
  formatPrice,
  getPricePerPerson,
  getSlotPricePerPerson,
  computeSlotPricing,
  roundCurrency,
  summarizePlan,
  toFloat,
};

export const products = {
  getDurationMinutes,
  getEnvironmentTag,
  normalizeNumeric,
  getPricePerPerson,
};

export const activities = {
  buildDays,
  createPlannedItem,
  updatePlannedItem,
  itemConflicts,
  findNextAvailableTime,
};

export {
  calculateTotalCost,
  deriveSlotPricing,
  formatPrice,
  getPricePerPerson,
  getSlotPricePerPerson,
  computeSlotPricing,
  roundCurrency,
  summarizePlan,
  toFloat,
  getDurationMinutes,
  getEnvironmentTag,
  normalizeNumeric,
  buildDays,
  createPlannedItem,
  updatePlannedItem,
  itemConflicts,
  findNextAvailableTime,
};
