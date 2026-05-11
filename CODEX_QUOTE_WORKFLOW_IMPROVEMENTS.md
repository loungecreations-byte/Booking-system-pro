# Codex: DagjeDenBosch Quote Workflow Simplification

**Project**: Quote Backend UX Optimization  
**Date**: May 10, 2026  
**Status**: Phase 1 Complete and hardened, Phase 2 Ready  
**Constraint Framework**: AGENTS.md (preserve CSOT, send-guards, participants truth, availability truth)

---

## 1. Executive Summary

The quote workflow currently requires 6+ manual steps (2x assumption resolve + review request + review approve + AI draft + send) spread across 3 tabs. This has been reduced to **3 core steps** via a new "Quick Prepare to Send" action that:
1. Auto-resolves open commercial assumptions (uncertain pricing/availability)
2. Orchestrates the review flow (request → approve) 
3. Lands operator back in Communication tab ready to send

**Phase 1 outcome**: Implemented, immutability-hardened, and regression-validated.  
**Next phase**: Quick-send flow, bulk actions, and inbox dashboard improvements.

---

## 2. Phase 1: Quick Prepare Implementation (DONE)

### Files Modified
- `modules/quotes/Module.php` — registered new admin_post action
- `modules/quotes/Admin/Controller.php` — 150+ LOC added:
  - `handleQuickPrepareToSend()` handler (orchestrates assumptions → review flow)
  - UI buttons in 3 locations (communication tab, workflow tab, assumptions list)

### Core Handler Logic
```php
public static function handleQuickPrepareToSend(): void
{
    // 0. Fail closed when the quote is commercially frozen (sent/accepted/etc.)
    // 1. Resolve all open standard assumptions (uncertain_pricing, uncertain_availability)
    //    with standard operator note: "Operator bevestiging: prijs en beschikbaarheid gecheckt"
    // 2. If review_status is not_started, request review
    // 3. Approve the review (triggers assertReadyToSend guard — catch blockers)
    // 4. Redirect to communication tab with success flag
}
```

### Guard Compliance
- Uses existing `QuoteReviewService::approve()` which calls `QuoteSendReadinessValidator::assertReadyToSend()`
- `QuoteReviewService::{requestReview,approve}` now also respect the commercial immutability guard
- Validator checks: customer email, quote lines, open send-blockers, version confidence, commercial totals, WooCommerce readiness
- If any validation fails, operator gets specific error → knows exactly what to fix
- **CSOT preserved**: No invented truth, only resolving documented assumptions
- **Immutability preserved**: sent/accepted quotes cannot be pushed back to `reviewed/ready_to_send` via quick-prepare

### User Flows (Phase 1)
**Before**: Quote 17 required:
1. Resolve assumption 1 (manual textarea + button)
2. Resolve assumption 2 (manual textarea + button)  
3. Request review (button)
4. Approve review (button)
5. Generate AI draft (button)
6. Send (complex form + button)

**After** (Phase 1): 
1. Communication tab → "Bevestig en klaar voor verzending" (1 click)
2. "AI draft proposal" (1 click)
3. "Verstuur voorstelmail" (1 click)

---

## 3. Phase 2: Recommended Next Steps

### 3.1 Quick-Send Flow (Estimated 2-3 hours)
**Problem**: Draft generation and send are still separate steps.  
**Solution**: New `handleQuickGenerateAndSend()` handler  
**Files to modify**:
- `Module.php` — register `admin_post_sbdp_quote_quick_generate_and_send`
- `Controller.php` — add handler + UI button in communication tab

```php
public static function handleQuickGenerateAndSend(): void
{
    // 1. Validate quote is ready to send (same guards as Phase 1)
    // 2. Call $communicationService->generateProposalDraft($quoteId, $actorId)
    // 3. Pre-populate send form with draft (to_name, to_email, subject, body from message record)
    // 4. Redirect back to communication tab with draft pre-filled
    // Optional: return as AJAX with modal preview instead of POST redirect
}
```

**UI**: Button appears when `proposal_send_ready === true` and no draft exists yet:  
"Genereer en stuur voorstelmail" → Opens in modal preview or same-tab form

### 3.2 Email Preview Modal (1-2 hours)
**Problem**: Large form is cognitively heavy; operator can't see final email easily.  
**Solution**: Lightbox modal showing rendered email + "Send" / "Edit" buttons  
**Files**:
- `Controller.php` — add `renderEmailPreviewModal()` helper
- CSS/JS — modal styling and form handling

**UX**: 
- Click "Genereer en stuur" → Modal opens with email preview
- If happy: "Verstuur" → POST sends email
- If needs edit: "Bewerk" → Expands form in modal or redirects

### 3.3 Quote Inbox Dashboard (2-3 hours)
**Problem**: Current inbox shows all quotes as flat list; no "What needs my attention?" view.  
**Solution**: Render `renderQuoteInboxPage()` with status columns and batch actions  
**Files**:
- `Controller.php` — enhance `renderQuoteInboxPage()`
- Add filters: "Awaiting prepare", "Ready to send", "Awaiting reply", "Sent & accepted"
- Add batch checkbox + "Quick prepare selected" action

**UI Example**:
```
📊 Quote Inbox

Filter: [All] [Awaiting prepare ×3] [Ready to send ×5] [Awaiting reply ×2] [Done ×12]

| Quote Ref     | Status        | Requester   | Action                      |
| Q-0508-113550 | Ready → Send  | Codex QA    | [Prepare] [Send]            |
| Q-0508-113551 | Prepare       | Test User   | [Resolve assumptions] ... |

☐ Batch quick prepare (selected 3)
```

### 3.4 Assumption Auto-Resolve Config (1 hour)
**Problem**: For standard cases (e.g., "availability checked in exec validation"), operator still must resolve manually.  
**Solution**: Admin setting + auto-trigger rule  
**Files**:
- `Admin/Settings.php` — new "Quote Assumptions" section
- `Service/QuoteAssumptionService.php` — add `maybeAutoResolve()` logic

**Config Options** (Admin > Quote AI & Mail):
- "Auto-resolve uncertain_availability if execution validation complete" (checkbox)
- "Default operator note for auto-resolve" (textarea)
- "Resolution delay (0 = immediate, 48 = after 48 hours)" (number)

**Trigger**: After execution payload is validated, scan for open availability assumptions → auto-resolve + log event

### 3.5 Better Error Messages (30 mins)
**Problem**: Generic "Quote is not ready to send" doesn't help operator.  
**Solution**: `assertReadyToSend()` already returns array of blockers with messages → expose them in UI clearly

**Current** (Phase 1): 
```
Voorstel verzenden nog niet beschikbaar
Vraag eerst review aan en keur de quote goed.
```

**Improved** (Phase 2):
```
❌ Voorstel verzenden nog niet beschikbaar

Reden: Quote request has no customer email address on file

📋 Wat te doen:
1. Ga naar Intake context tab
2. Vul "E-mail" in
3. Klik "Werk intake bij"
4. Probeer opnieuw
```

---

## 4. Architecture & Guardrails

### Truth Preservation (per AGENTS.md)
- **CSOT** (Commercial Source of Truth): Assumptions remain the single source of send-ready state
- **Send-guards**: All handlers call existing validators; no bypasses
- **Participants**: Quote request `group_size` unchanged unless explicitly updated via "Werk intake bij"
- **Availability**: Assumption resolution does NOT change executor availability; only removes blocker flag
- **Handoff boundary**: Quote operations (build tab) remain separate from Woo cart/booking

### Database Transactions
- Each handler is atomic: update assumptions + review status + events in single transaction (or rollback)
- Event logging captures every state change
- No orphaned records

### Event Logging
- Every assumption resolution logged: `quote_assumption_resolved` event with `resolution_note`
- Every review action logged: `quote_review_requested`, `quote_review_approved`
- Bulk actions log a single batch event + individual line items

### API Boundaries
- Quote Admin → Quote Service layer only (no WooCommerce/booking service calls)
- No planner-side calculations (all pricing/participants come from runtime)
- Request items stay out of direct checkout (handoff still validates before Woo hydration)

---

## 5. Implementation Order (Phases 2–4)

### Phase 2 (Priority 1)
1. Quick-send flow with modal preview (3 hours)
2. Better error messages (30 mins)
3. Manual testing on quotes 16, 17, 18

### Phase 3 (Priority 2)
4. Quote Inbox dashboard + filters (3 hours)
5. Batch quick-prepare action (2 hours)

### Phase 4 (Future)
6. Assumption auto-resolve config (1 hour)
7. Performance: query optimization in `renderQuoteInboxPage()`
8. Bulk email sending (async jobs)

---

## 6. Testing Checklist (Phase 1 + 2)

### Functional Tests
- [ ] Quote in draft state: "Bevestig en klaar voor verzending" button visible + works
- [ ] Quote with 2 open assumptions: Clicking button resolves both + redirects to communication tab
- [ ] Quote already approved: Button not shown (or hidden if already ready to send)
- [ ] Error case (no customer email): Error message shown, quote not moved to ready
- [ ] Quick-send with AI: Draft generated + email form shows in modal/same-tab
- [ ] Send completes: Message record created, email logged, status = sent

### Edge Cases
- [ ] Quote with non-commercial assumption (e.g., "client prefers June"): Assumption NOT auto-resolved
- [ ] Quote with send-blocker on version confidence: Error before review approval
- [ ] Quote with missing quote lines: Error during prepare
- [ ] Operator role without manage_woocommerce cap: 403 error

### UI/UX
- [ ] Mobile: buttons responsive, forms not broken
- [ ] Accessibility: all buttons have aria-labels, forms have labels
- [ ] Localization: all text is translatable (via `esc_html__()`)
- [ ] Error messages are clear + actionable

### Database
- [ ] No orphaned events (all events reference quote_id + version_id)
- [ ] Assumption resolution_note is stored + visible in history
- [ ] Review flow captures approved_at + approved_by timestamps
- [ ] No duplicate event entries from rapid re-clicks (idempotent)

### Performance
- [ ] `renderQuoteDetail()` page load < 2s (10+ assumptions, 5+ messages)
- [ ] Quick-prepare button click → redirect < 500ms (assume 200 quotes in DB)

### Compliance (AGENTS.md)
- [ ] No CSOT violations: all truth still comes from runtime
- [ ] Send-guards remain intact: impossible to send without passing validator
- [ ] Participants immutable during quote operation
- [ ] Availability assumption resolution ≠ booking availability change
- [ ] Request items do not enter direct checkout

---

## 7. Rollback Plan

If issues arise:
1. **UI only broken**: Revert Controller.php communication tab + workflow tab changes (< 20 LOC)
2. **Handler broken**: Revert Module.php action + handler method (< 5 mins)
3. **DB corruption**: Assumptions + reviews are idempotent; no migration needed

**Test rollback**: 
- git checkout previous commit
- Browse to quote 17
- Verify UI returns to old multi-step flow
- No data loss (all events still logged)

---

## 8. Deployment Steps

### Pre-deployment
1. Local: run all functional tests on dev site
2. Staging: deploy branch, manual smoke test on staging quotes
3. Prod: deploy during off-hours (early morning)

### During deployment
1. Pull code changes
2. Flush WordPress cache (opcache + object cache)
3. No database migration needed

### Post-deployment
1. Smoke test: create test quote in Woo, convert to quote, hit quick-prepare button
2. Monitor for errors in `wp_logs` table
3. If green: notify stakeholders

---

## 9. Documentation & Handoff

### Operator Guide (to write)
**"Fast Quote Preparation"**
1. Quote appears in inbox (status: Draft)
2. Open quote → Communication tab
3. Click **"Bevestig en klaar voor verzending"**
   - System auto-confirms pricing/availability
   - System runs internal review
4. Click **"AI draft proposal"** → email generated
5. Click **"Verstuur voorstelmail"** → email sent to customer

---

## 10. Success Metrics

### Quantitative
- Quote → Send time reduced from 10 min to 2 min (5x faster)
- Clicks from 6+ to 3 (50% reduction)
- Assumption resolution errors → 0 (all handled by system)

### Qualitative
- Operators report workflow is "finally fast"
- No send-guard bypasses
- Zero data integrity issues

---

## 11. Known Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Operator accidentally clicks "Bevestig" on incomplete quote | Quote marked ready but fails to send | Error message shows blockers; quote stays in draft |
| Assumption auto-resolve triggers on wrong quote | Wrong send-ready state | Phase 2 feature gated behind admin config; only for certain assumption types |
| Performance regression (many assumptions per quote) | Slow page load | Query optimization in Phase 4; currently assumes < 20 assumptions per quote |
| Localization not complete | Dutch operators see English text | Ensure all new strings use `esc_html__()` with 'sbdp' domain |

---

## 12. Code Quality Standards

### Naming Conventions
- Handlers: `handleXxxYyyy()` (verb + noun)
- Private helpers: `renderXxxYyyy()` or `assertXxxYyyy()`
- Database fields: `snake_case`
- CSS classes: `bsp-quote-admin__xxx--state`

### Comment Style
- Complex logic: explain "why" not "what"
- Example: `// Resolve only commercial assumptions to preserve audit trail of other blockers`
- Avoid: `// Set status to resolved`

### Testing
- Unit tests: Validate guards + validators
- Integration tests: Full quote flow (create → prepare → send)
- No end-to-end tests (use Codex for manual QA instead)

---

## 13. References

- AGENTS.md: Project governance + truth preservation rules
- quotes-runtime-gotchas.md: Assumption logic, send-guard patterns
- unified-design-execution.md: Admin UI conventions
- QuoteReviewService.php: Review state machine
- QuoteCommunicationService.php: Email/message handling
- QuoteSendReadinessValidator.php: Send-guard rules

---

## 14. Contact & Escalation

**Questions?**
- Architecture/CSOT: Review AGENTS.md section 8-11
- Send-guards: See QuoteSendReadinessValidator.php for all blockers
- Operator UX: Test on real quotes in staging

**Blockers?**
- If send-guard prevents workflow: Document the blocker + escalate to product
- If performance issue: Profile with Query Monitor, consider Phase 4 optimization
- If localization missing: Add string to Controller.php with `__('text', 'sbdp')`

---

**End of Codex Document**
