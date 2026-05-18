import test from "node:test";
import assert from "node:assert/strict";

import { buildPlannerCtaModel } from "../../assets/js/day-planner/app/utils/planner-cta.js";
import {
  isHardAvailabilityBlocker,
  isNonDefinitiveAvailabilityIssue,
} from "../../assets/js/day-planner/app/utils/planner-state.js";

test("direct capability shows checkout as primary action", () => {
  const model = buildPlannerCtaModel({
    plannerActionState: {
      action_mode: "direct",
      primary_cta_enabled: true,
      secondary_quote_enabled: true,
    },
    formattedTotal: "€770,00",
  });

  assert.equal(model.primary.key, "checkout");
  assert.equal(model.primary.variant, "primary");
  assert.equal(model.primary.enabled, true);
  assert.match(model.primary.label, /Boek mijn dag/);
  assert.equal(model.secondary.key, "quote");
});

test("availability-check-needed request status shows quote as enabled primary action", () => {
  const model = buildPlannerCtaModel({
    plannerActionState: {
      action_mode: "request",
      primary_cta_enabled: false,
      secondary_quote_enabled: true,
      route_intent: "checkout",
      availability_issue_visible: true,
      blocking_reason_code: "availability_suggested_start",
    },
    formattedTotal: "€770,00",
  });

  assert.equal(model.primary.key, "quote");
  assert.equal(model.primary.variant, "primary");
  assert.equal(model.primary.enabled, true);
  assert.equal(model.primary.label, "Vraag beschikbaarheid & offerte aan");
  assert.equal(model.priceLabel, "Voorlopige richtprijs. Beschikbaarheid en offerte worden eerst gecontroleerd.");
  assert.notEqual(model.primary.key, "checkout");
});

test("availability_check_needed with suggested start is classified as request-safe", () => {
  assert.equal(
    isNonDefinitiveAvailabilityIssue(
      { message: "Beschikbaarheid controleren: gekozen tijd lijkt niet beschikbaar. Mogelijke optie: 06:00." },
      "availability_check_needed"
    ),
    true
  );
});

test("selected time unavailable with suggested alternative is request, not hard blocked", () => {
  assert.equal(
    isNonDefinitiveAvailabilityIssue(
      { message: "Misschien tijdslot mogelijk: 06:00." },
      "selected_time_unavailable_with_alternative"
    ),
    true
  );
  assert.equal(isHardAvailabilityBlocker("selected_time_unavailable_with_alternative"), false);
});

test("request-only items do not expose direct checkout as the primary action", () => {
  const model = buildPlannerCtaModel({
    plannerActionState: {
      action_mode: "request",
      primary_cta_enabled: false,
      secondary_quote_enabled: true,
      route_intent: "quote",
    },
    formattedTotal: "€225,00",
  });

  assert.equal(model.primary.key, "quote");
  assert.equal(model.secondary.key, "review");
});

test("hard blocked plan shows resolve action and no quote action", () => {
  const model = buildPlannerCtaModel({
    plannerActionState: {
      action_mode: "blocked",
      primary_cta_enabled: false,
      secondary_quote_enabled: false,
      route_intent: "blocked",
    },
  });

  assert.equal(model.primary.key, "resolve");
  assert.equal(model.primary.label, "Los planning op");
  assert.equal(model.secondary, null);
  assert.equal(isHardAvailabilityBlocker("capacity_exceeded"), true);
});

test("empty plan shows add activities action", () => {
  const model = buildPlannerCtaModel({
    plannerActionState: {
      action_mode: "empty",
      primary_cta_enabled: false,
      secondary_quote_enabled: false,
    },
  });

  assert.equal(model.primary.key, "add");
  assert.equal(model.primary.label, "Voeg activiteiten toe");
});

test("missing required fields remain non-checkout states", () => {
  const model = buildPlannerCtaModel({
    plannerActionState: {
      action_mode: "blocked",
      primary_cta_enabled: false,
      secondary_quote_enabled: false,
      blocking_reason_code: "incomplete_plan",
    },
  });

  assert.equal(model.primary.key, "resolve");
  assert.equal(model.primary.enabled, true);
});

test("desktop summary and mobile callers derive the same action from the same state", () => {
  const plannerActionState = {
    action_mode: "request",
    primary_cta_enabled: false,
    secondary_quote_enabled: true,
    route_intent: "quote",
  };

  const desktop = buildPlannerCtaModel({ plannerActionState, formattedTotal: "€770,00" });
  const mobile = buildPlannerCtaModel({ plannerActionState, formattedTotal: "€770,00" });

  assert.deepEqual(desktop.primary, mobile.primary);
  assert.deepEqual(desktop.secondary, mobile.secondary);
});
