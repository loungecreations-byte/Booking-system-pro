# DDB Release Gates

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Mandatory release gates and pass/fail rules. A release is blocked until all applicable gates pass.

## Gate 1 — Authority docs

**Pass:** All 17 authority documents are present and readable.
**Fail:** One or more authority docs missing at expected path.
**Block:** Release blocked until all docs are present.

## Gate 2 — Shell integrity

**Pass:** All WooCommerce template overrides carry their canonical shell markers.
**Fail:** One or more overrides missing the expected marker.
**Block:** Release blocked.

## Gate 3 — Booking truth

**Pass:** All products route correctly (direct / request / supplier confirmation).
**Fail:** Any request-only product can reach checkout; product 115 has `directBookable: true`.
**Block:** Release blocked; security severity.

## Gate 4 — Design system

**Pass:** No inline `style=` drift in critical templates; no `!important` on token properties.
**Fail:** Inline style or `!important` drift detected.
**Block:** Release blocked.

## Gate 5 — Test suite

**Pass:** All PHPUnit tests pass with zero failures.
**Fail:** Any test failure.
**Block:** Release blocked.

## Gate 6 — Regression checklist

**Pass:** All items in `DDB_REGRESSION_CHECKLIST.md` are checked.
**Fail:** One or more unchecked items.
**Block:** Release blocked for launch; warning for patch releases.

## Gate 7 — OMDB / Woo boundaries

**Pass:** No planner-side price calculation; no provider price as Woo truth; no Eliio POST for direct checkout.
**Fail:** Any boundary violation detected.
**Block:** Release blocked; commerce integrity severity.
