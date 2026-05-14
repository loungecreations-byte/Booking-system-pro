import test from "node:test";
import assert from "node:assert/strict";

import {
  applyParticipantsTruthToItem,
  buildAutoTimeFields,
  buildInheritedParticipants,
  buildManualParticipants,
  buildManualTimeFields,
  countCriticalPlannerItemOverlaps,
  resolveParticipantsForItem,
  shouldApplyAvailabilitySuggestedStart,
} from "../../assets/js/day-planner/app/utils/planner-state.js";

test("global participants propagate to items without manual override", () => {
  const items = [
    { id: "boat", productId: 101, participants: 10, participants_source: "inherited" },
    { id: "walk", productId: 102, participants: 2, participants_source: "product_default" },
  ];

  const nextItems = items.map((item) => applyParticipantsTruthToItem(item, 11));

  assert.equal(nextItems[0].participants, 11);
  assert.equal(nextItems[1].participants, 11);
  assert.equal(nextItems[0].participants_override, false);
  assert.equal(nextItems[1].participants_source, "inherited");
});

test("manual item participants override survives global participants change", () => {
  const manualItem = {
    id: "private-room",
    participants: 6,
    participants_override: true,
    participants_source: "manual_override",
  };
  const inheritedItem = buildInheritedParticipants(10);

  assert.equal(resolveParticipantsForItem(manualItem, 11), 6);
  assert.equal(resolveParticipantsForItem(inheritedItem, 11), 11);
});

test("new items inherit canonical plan participants, not product defaults", () => {
  const productDefault = 2;
  const item = {
    id: "new-boat",
    participants: productDefault,
    participants_source: "product_default",
  };

  const normalized = applyParticipantsTruthToItem(item, 11);

  assert.equal(normalized.participants, 11);
  assert.equal(normalized.participants_override, false);
  assert.notEqual(normalized.participants, productDefault);
});

test("product min and max context does not mutate participants truth", () => {
  const item = { id: "capacity-limited", participants: 10 };
  const product = { people: { enabled: true, min: 1, max: 2 } };

  const normalized = applyParticipantsTruthToItem(item, 11);

  assert.equal(product.people.max, 2);
  assert.equal(normalized.participants, 11);
});

test("manual Boottocht time is locked and availability suggestion is not applied", () => {
  const boat = {
    id: "boottocht",
    title: "Boottocht",
    startTime: "14:00",
    endTime: "15:00",
    ...buildManualTimeFields(),
  };

  const movedEarlier = {
    ...boat,
    startTime: "12:30",
    endTime: "13:30",
    ...buildManualTimeFields(),
  };

  assert.equal(movedEarlier.startTime, "12:30");
  assert.equal(movedEarlier.manual_locked, true);
  assert.equal(movedEarlier.time_source, "manual");
  assert.equal(shouldApplyAvailabilitySuggestedStart(movedEarlier, { suggestedStart: "19:00" }), false);
});

test("auto item can only be rescheduled by explicit auto optimize intent", () => {
  const autoItem = {
    id: "auto",
    startTime: "14:00",
    ...buildAutoTimeFields(),
  };

  assert.equal(shouldApplyAvailabilitySuggestedStart(autoItem, { suggestedStart: "19:00" }), false);
  assert.equal(
    shouldApplyAvailabilitySuggestedStart(autoItem, {
      suggestedStart: "19:00",
      explicitAutoReschedule: true,
    }),
    true
  );
});

test("manual participants mark only the edited item as override", () => {
  const inherited = buildInheritedParticipants(11);
  const overridden = buildManualParticipants(6, 11);

  assert.equal(inherited.participants, 11);
  assert.equal(inherited.participants_override, false);
  assert.equal(overridden.participants, 6);
  assert.equal(overridden.participants_override, true);
  assert.equal(overridden.participants_source, "manual_override");
});

test("real item overlap is counted as direct checkout blocker input", () => {
  const timeToMinutes = (value) => {
    const [hours, minutes] = value.split(":").map((part) => Number.parseInt(part, 10));
    return hours * 60 + minutes;
  };
  const items = [
    { id: "dinner", dayIndex: 0, startTime: "19:00", endTime: "21:00" },
    { id: "tour", dayIndex: 0, startTime: "20:00", endTime: "22:00" },
    { id: "short", dayIndex: 0, startTime: "20:30", endTime: "21:30" },
  ];

  assert.equal(countCriticalPlannerItemOverlaps(items, timeToMinutes), 2);
});

test("items in the same arrangement group do not count as overlap blockers", () => {
  const timeToMinutes = (value) => {
    const [hours, minutes] = value.split(":").map((part) => Number.parseInt(part, 10));
    return hours * 60 + minutes;
  };
  const items = [
    { id: "anchor", dayIndex: 0, groupId: "arrangement-1", startTime: "19:00", endTime: "21:00" },
    { id: "segment", dayIndex: 0, groupId: "arrangement-1", startTime: "20:00", endTime: "22:00" },
  ];

  assert.equal(countCriticalPlannerItemOverlaps(items, timeToMinutes), 0);
});
