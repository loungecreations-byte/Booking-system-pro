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

test("home widget ingress has no legacy page-load item generation path", () => {
  const guard = source.indexOf("Activity generation requires explicit user intent.");
  const guardReturn = source.indexOf("homeWidgetAppliedRef.current = true;", guard);

  assert.ok(guard > -1, "expected context-only prefill guard");
  assert.ok(guardReturn > guard, "expected page-load guard to mark ingress applied");
  assert.equal(
    source.includes("Clearing existing plan items before auto-fill"),
    false,
    "legacy clear-items path must be removed"
  );
  assert.equal(source.includes("Starting auto-fill"), false, "legacy auto-fill path must be removed");
  assert.equal(
    source.includes("Using pre-fetched AI suggestions"),
    false,
    "legacy AI apply path must be removed"
  );
  assert.equal(
    source.includes("sbdp_ai_suggestions"),
    false,
    "session AI suggestions must not be consumed on page load"
  );
  assert.equal(
    source.includes("SBDP_AI_SUGGESTIONS"),
    false,
    "global AI suggestions must not be consumed on page load"
  );
});

test("product prefill does not commit an activity on load", () => {
  const guard = source.indexOf("Product prefill detected on load; not committing an activity");
  const guardEnd = source.indexOf("prefillPlanAppliedRef.current = true;", guard);
  const branchEnd = source.indexOf("}", guardEnd);
  const guardedBranch = source.slice(guard, branchEnd);

  assert.ok(guard > -1, "expected product prefill no-commit guard");
  assert.ok(guardEnd > guard, "expected product prefill to mark itself handled");
  assert.equal(
    guardedBranch.includes("addActivityFn("),
    false,
    "product prefill branch must not add an activity on page load"
  );
});
