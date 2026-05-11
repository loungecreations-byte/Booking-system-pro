# ServiceNow-Inspired Quote Backend Transformation
## DagjeDenBosch Quotes v2.0

**Baseline**: ServiceNow CPQ philosophy, adapted to WordPress/WooCommerce boundaries  
**Current status**: MVP release-safe foundation is implemented  
**Last updated**: May 11, 2026  
**Decision rule**: Build only what preserves Woo, quote immutability, approved-version handoff, and send-readiness truth.

---

## 1. Current Truth

DagjeDenBosch now has a working quote execution foundation:

- Woo checkout "Plaats bestelling" works.
- Normal Woo checkout works.
- BSP direct booking checkout works.
- Quote handoff checkout works end to end.
- Quote `19` -> `approved_version_id 23` -> Woo order `2610` succeeded.
- Woo order total `225.00 EUR` matched accepted quote total `225.00 EUR`.
- Woo order item meta contains `quote_id=19` and `quote_version_id=23`.
- `quote_version_id` equals the pinned `approved_version_id`.
- Public payment/order mutation routes remain blocked.
- Public booking create/request does not trust client price/status/truth.
- Quote immutability after sent/accepted is enforced.
- Quote send-readiness blockers are enforced.
- Quick-prepare, review, and assumption-resolve cannot mutate frozen sent/accepted quotes.

This means the release-critical backend path is not the problem anymore. The next work is operator workflow and post-MVP governance.

---

## 2. Two Dashboard Levels

### Level 1: Per-Quote Focus Dashboard

**Status**: Implemented.

Purpose:
- When an operator opens one quote, answer: "Wat moet ik nu doen?"
- Show one primary state at a time.
- Hide lower-priority checks until the first blocker is solved.

Implemented states:
- `blocked`: one red focus card with one action.
- `assumptions`: orange confirmation flow for resolvable price/availability checks.
- `ready`: green state that routes to the existing Communication send path.
- `locked`: frozen sent/accepted quote shows audit-only context.

Implemented files:
- `modules/quotes/Service/DashboardBlockerService.php`
- `modules/quotes/Service/QuoteBusinessRuleValidator.php`
- `modules/quotes/Admin/Controller.php`

Why ready routes to Communication:
- Sending from the dashboard would create another send surface.
- Existing `QuoteCommunicationService` and `QuoteSendReadinessValidator` remain the backend send authority.
- This preserves send guards and avoids a hidden bypass.

### Level 2: Global Quote Overview Dashboard

**Status**: Post-MVP, recommended next.

Purpose:
- When an operator opens Quotes, answer: "Welke offerte heeft nu aandacht nodig?"
- This is the ServiceNow-style command center.

Safe first version:
- Read-only quote pipeline.
- Filters: `Actie nodig`, `Klaar voor verzending`, `Verzonden`, `Geaccepteerd`, `Handoff/order`.
- Columns: quote reference, customer, date, group size, primary dashboard blocker, send status, handoff status, latest follow-up.
- One CTA per row: open quote dashboard.
- No bulk mutation in v1.

Do not add initially:
- Bulk prepare.
- Bulk assumption resolve.
- Bulk review approve.
- Pricing edits.
- Direct send from list.

Reason:
- Bulk mutation can resolve commercial assumptions and approve review across multiple records.
- That needs separate audit, permission, and rollback design.

---

## 3. Implemented ServiceNow Principles

### Unified Workspace

Status: Done for individual quote workspace.

What works:
- One quote dashboard tab.
- Operator focus card.
- Customer snapshot.
- Business validation.
- Send-readiness integration.
- Assumption confirmation.
- Communication tab remains the send surface.
- History/advanced data remains available but secondary.

Remaining:
- Global quote overview dashboard.

### Validation Guardrails

Status: Release-safe core implemented.

Implemented:
- `QuoteSendReadinessValidator`
- `QuoteBusinessRuleValidator`
- `DashboardBlockerService`
- Woo direct-checkout quote line readiness
- Missing email blocker
- Missing quote lines blocker
- Missing pricing/availability confidence blockers
- Open send-blocker assumption blocker
- Invalid total/currency/VAT/product blockers

Deferred:
- Rule-driven assumption auto-validation.
- Manager-configured business rules.

Rule:
- Auto-validation may create guidance.
- It may not silently change availability, pricing, booking, or send truth.

### Transparency And Audit Trail

Status: Implemented for core quote actions.

Existing:
- Quote events.
- Assumption resolution note.
- Actor/timestamp tracking.
- Review/send/handoff events.
- Version-pinned quote handoff.

Post-MVP improvement:
- A clearer "operator timeline" panel on the global dashboard.

---

## 4. Needs Decision Before Code

These ideas are useful but unsafe to build blindly.

### Configuration / Pricing Engine

Status: `NEEDS_DECISION`.

Risk:
- Can become a second commercial source of truth.
- Can conflict with Woo final price, VAT, cart, checkout, and order totals.

Allowed shape:
- Read-only guidance.
- Draft quote-line suggestions.
- Manager-approved rule metadata.
- Explicit conversion into quote lines that are later validated by Woo/runtime.

Forbidden shape:
- UI-configured pricing that directly becomes final customer total.
- Frontend pricing arithmetic.
- VAT calculation outside Woo-aware helpers.
- Runtime checkout totals that do not come from Woo/booking.

Decision needed:
- Who owns commercial rule changes?
- Are rules advisory or executable?
- How are rule changes audited?
- How does a rule become a quote-line snapshot?
- How is Woo final truth reconciled?

### Discovery Flow

Status: `NEEDS_DECISION`.

Safe shape:
- Intake questionnaire.
- Operator guidance.
- Suggested products as "mogelijk passend".
- Assumptions generated as review prompts.

Unsafe shape:
- Auto-selecting request items into checkout.
- Auto-calculated pricing as final quote truth.
- Treating product suggestions as availability confirmation.

### Customer Portal / Partner Portal

Status: Post-MVP / `NEEDS_DECISION`.

Safe first step:
- Read-only customer quote view.
- No edit capability.
- No booking mutation.
- No payment/order mutation outside Woo checkout.

Decision needed:
- Authentication model.
- Link expiry.
- Data exposure rules.
- Which quote statuses are visible.
- Whether customer acceptance happens there or remains via existing controlled handoff.

### Multi-Channel API

Status: Post-MVP / `NEEDS_DECISION`.

Safe first step:
- Read-only quote status endpoint for authenticated admin/internal users.

Unsafe without review:
- Public send endpoint.
- Public assumption resolve endpoint.
- Public order/payment mutation endpoint.
- Channel-specific pricing or availability logic.

### Bulk Prepare

Status: Deferred.

Reason:
- It mutates commercial records in batches.
- It can resolve assumptions and approve review without enough operator attention.

Safe future design:
- Dry-run first.
- Show per-quote blocker summary.
- Require explicit confirmation.
- Audit every quote separately.
- Never bypass `QuoteSendReadinessValidator`.

---

## 5. Post-MVP Backlog

Priority order:

1. Global Quote Overview Dashboard
   - Read-only command center for all open quotes.
   - Uses existing `DashboardBlockerService` output.
   - One row CTA: open quote.

2. Quote-side Woo Order Backfill
   - After quote-originated checkout, backfill `woo_order_id` onto the quote.
   - Woo order item meta is already correct.
   - This improves admin reconciliation.

3. Full Authenticated Browser Smoke
   - One command test for quote -> sent -> accepted -> handoff -> cart -> checkout -> order.
   - Current proof exists as live/manual evidence plus service tests.

4. Quote-to-Order Reconciliation View
   - Show linked Woo order, status, amount, and version meta in quote admin.
   - Read-only at first.

5. Discovery Guidance
   - Intake questions and suggestions only.
   - No auto-pricing truth.

6. Config Rule Design
   - Architecture decision before code.
   - Start with read-only rule metadata and audit trail.

7. Customer/Partner Portal
   - Read-only first.
   - Auth and data exposure decision required.

8. Handoff Idempotency
   - Refresh/double-click/expired-link behavior.
   - Must preserve approved-version and Woo boundaries.

---

## 6. Guardrails

Never do this for speed:

- Skip `QuoteSendReadinessValidator`.
- Send from UI without backend validation.
- Mutate sent/accepted quote commercial content.
- Overwrite `approved_version_id`.
- Build handoff from `current_version_id`.
- Let request items enter direct checkout.
- Recalculate Woo totals or VAT in quote UI.
- Treat assumption resolution as real booking availability.
- Use frontend price math as customer-facing truth.
- Add public payment/order mutation routes.

Always preserve:

- WooCommerce owns cart, checkout, payment, VAT, order, and order status.
- Booking runtime owns final availability/bookability.
- `approved_version_id` owns quote handoff source.
- Quote events own audit trail.
- Assumptions and send status remain readiness inputs.
- Frozen sent/accepted quotes are audit-only unless a revision flow is explicitly used.

---

## 7. Current Success Criteria

Release-ready criteria already met:

- Normal Woo checkout passes.
- BSP direct booking checkout passes.
- Quote handoff checkout passes.
- Bad quotes cannot be sent.
- Public mutation routes remain blocked.
- Approved-version handoff boundary is intact.
- Quote immutability is intact.
- Operator dashboard shows one main next action.

Post-MVP success criteria:

- Operators can see all quote work in one global overview.
- Quote admin shows linked Woo order after checkout.
- Full quote handoff smoke runs as one authenticated browser test.
- Any config/discovery work remains advisory until explicitly promoted through approved quote-line/runtime paths.

---

## 8. Final Position

The ServiceNow direction is valid, but only if it stays inside DagjeDenBosch truth boundaries.

Current answer:
- Per-quote dashboard: done.
- Release backend: done.
- Global quote overview dashboard: next sensible post-MVP feature.
- Pricing/config engine: decision required before implementation.
- Portals/multi-channel: post-MVP and security-reviewed.

Do not build Phase 3-5 as raw code from this document. Use this document as the decision map.
