import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const providerPath = resolve(
  __dirname,
  "../../assets/js/day-planner/store/PlannerProvider.jsx"
);
const source = readFileSync(providerPath, "utf8");

test("home widget ingress hydrates context before any legacy page-load item generation path", () => {
  const guard = source.indexOf("Activity generation requires explicit user intent.");
  const guardReturn = source.indexOf("homeWidgetAppliedRef.current = true;", guard);
  const clearItems = source.indexOf("Clearing existing plan items before auto-fill");
  const autoFill = source.indexOf("Starting auto-fill");
  const aiApply = source.indexOf("Using pre-fetched AI suggestions");

  assert.ok(guard > -1, "expected context-only prefill guard");
  assert.ok(guardReturn > guard, "expected page-load guard to mark ingress applied");
  assert.ok(clearItems > guardReturn, "legacy clear-items path must stay behind guard");
  assert.ok(autoFill > guardReturn, "legacy auto-fill path must stay behind guard");
  assert.ok(aiApply > guardReturn, "legacy AI apply path must stay behind guard");
});

test("product prefill does not commit an activity on load", () => {
  const guard = source.indexOf("Product prefill detected on load; not committing an activity");
  const addActivity = source.indexOf("source: \"product-prefill\"", guard);

  assert.ok(guard > -1, "expected product prefill no-commit guard");
  assert.ok(addActivity > guard, "legacy product add path must stay behind guard");
});
