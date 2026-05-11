---
name: ddb-booking-flow-qa
description: End-to-end QA for DagjeDenBosch booking flows, including participants truth, availability decisions, request vs direct routing, planner continuity, cart/checkout integrity, and mobile behavior.
---

# DDB Booking Flow QA

## Use when
Use this skill when testing:
- overview/detail/add-to-day/planner/cart/checkout flows
- participants changes
- availability or slots behavior
- request vs direct routing
- combo behavior
- quote/offerte routing from planner or detail pages
- regressions after implementation

## Required reading
Read in order:
1. `AGENTS.md`
2. `docs/DDB_OMDB_WOO_BOUNDARIES.md`
3. `docs/DDB_PARTICIPANTS_TRUTH.md`
4. `docs/DDB_AVAILABILITY_TRUTH.md`
5. `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`
6. any governor or audit output for the current task

## QA flow sequence
1. Start from the real entrypoint.
2. Test detail, selection, planner, cart, checkout, and request routing where relevant.
3. Verify participants truth continuity.
4. Verify availability decision continuity.
5. Verify request items do not silently enter direct checkout.
6. Verify combo outcomes match required-component rules.
7. Verify desktop and mobile viewports.
8. Record reproducible defects and likely cause.

## Mandatory participants truth QA
Always test:
1. Type a valid participants value and immediately click the next action without relying on manual blur.
2. Confirm the added planner item uses the same participants value.
3. Confirm planner timeline uses the same participants value.
4. Confirm planner summary uses the same participants value.
5. Confirm cart summary uses the same participants value.
6. Confirm checkout meta uses the same participants value.
7. Repeat on mobile viewport.

## Mandatory availability truth QA
Always test:
1. a direct-bookable case
2. a request-only case
3. an unavailable case
4. a hybrid/product-above-direct-limit case
5. a combo case where one required component becomes request-only

## Output format
Return exactly:
1. Tested flow
2. Scenarios
3. Successful checks
4. Defects with reproduction steps
5. Truth violated
6. Likely cause
7. Smallest safe fix direction
8. Blocking / non-blocking
9. Release advice