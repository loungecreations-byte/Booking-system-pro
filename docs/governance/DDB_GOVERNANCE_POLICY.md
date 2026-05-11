# DagjeDenBosch Governance Policy

## Purpose
This policy defines how DagjeDenBosch governs digital change, release quality, architectural compliance, and launch readiness.

This policy exists to ensure that:
- the platform remains aligned with the target architecture
- releases are controlled and reviewable
- design/system truth is enforced
- OMDB and WooCommerce truth remain protected
- speed does not create structural damage

---

## 1. Governance objective

DagjeDenBosch must not evolve through ad-hoc page changes, local fixes, or isolated plugin behavior.

All meaningful digital changes must be governed to protect:
- platform consistency
- design system truth
- domain truth
- commercial execution truth
- release safety
- conversion quality
- customer trust

---

## 2. Scope

This policy applies to all digital changes affecting:

- website
- homepage
- activities
- spots
- spot detail
- product detail
- planner / plan je dag
- planning cart
- checkout / afrekenen
- account
- portal
- tours / beleven layer
- upsell surfaces
- shell/header/footer
- shared UI components
- page templates
- booking-related customer-facing flows

This policy also applies to changes that affect:
- Design CSOT
- OMDB
- WooCommerce execution truth
- planner continuity
- add-to-day handoff
- launch readiness

---

## 3. Governance principles

### 3.1 One platform principle
DagjeDenBosch is one product ecosystem.
No page, plugin, or module may behave like its own disconnected product.

### 3.2 CSOT principle
The platform must have one enforceable source of truth per layer:
- Design CSOT
- Domain CSOT
- Execution / Commerce truth

### 3.3 Review-before-release principle
No significant release may go live without passing the required gates.

### 3.4 Safety-over-speed principle
Speed is important, but business truth, booking truth, shell integrity, and customer trust take precedence.

### 3.5 Explicit exception principle
If a release does not meet governance requirements, it may only proceed through an explicit exception process.

---

## 4. The 3 truths

### 4.1 Design CSOT
The visual source of truth is:
- one active design-system runtime for token output and theme mapping (currently `ddb-core-ui/core-ui.php`)
- compatibility bridges may exist in MU/bootstrap layers, but they may not emit competing public stylesheet truth when `ddb-core-ui` is active
- the DagjeDenBosch Unified Design System

### 4.2 Domain CSOT
The domain source of truth is:
- OMDB

### 4.3 Execution / Commerce truth
The execution source of truth is:
- WooCommerce + booking layer

No release may blur these boundaries.

---

## 5. Governance layers

### 5.1 Strategy / architecture governance
Checks whether a change aligns with:
- constitution
- page-family rules
- CTA map
- component canon
- shell law
- target architecture

### 5.2 Design-system governance
Checks whether a change:
- uses shared primitives
- avoids page-local design truth
- avoids duplicate component families
- respects dark/light and responsive rules

### 5.3 Domain/commercial governance
Checks whether a change:
- preserves OMDB meaning
- preserves Woo pricing truth
- preserves booking truth
- avoids pricing/availability duplication in UI

### 5.4 Release governance
Checks whether a release is:
- reviewed
- tested
- documented
- launch-ready
- reversible if needed

---

## 6. Required governance documents

The following documents are mandatory governance sources:

- `AGENTS.md` — platform law, review agent index
- `app/public/wp-content/plugins/booking-pro-module/AGENTS.md` — BPM-specific PR review guide (pricing, VAT, security)
- `docs/DDB_PLATFORM_CONSTITUTION.md` — tech stack, token chain, route architecture
- `docs/DDB_CTA_MAP.md` — CTA hierarchy per page family
- `docs/DDB_DO_NOT_TOUCH.md` — protected files and patterns
- `docs/DDB_PAGE_FAMILIES.md` — canonical page families with H-structure and template refs
- `docs/DDB_COMPONENT_CANON.md` — 10 canonical component families
- `docs/DDB_OMDB_WOO_BOUNDARIES.md` — domain/commerce/presentation boundaries
- `docs/DDB_PARTICIPANTS_TRUTH.md` — canonical participants handoff truth
- `docs/DDB_AVAILABILITY_TRUTH.md` — canonical booking capability and route-intent truth
- `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md` — provider capability separation and request/direct guardrails
- `docs/DDB_SHELL_RULES.md` — shell order, route CSS rules
- `docs/DDB_LAUNCH_BOARD.md` — launch readiness per page family
- `docs/DDB_REGRESSION_CHECKLIST.md` — what to check after each implementation pass
- `docs/DDB_IMPLEMENTATION_SEQUENCE.md` — phase order and completion status
- `docs/DDB_REVIEW_LOOP.md` — review agent sequence

### Operational execution layer (docs/01–10)
The `docs/01-` through `docs/10-` agent files define the *roles and responsibilities* of each review agent. They are the operational layer of this governance policy:

| Agent file | Role |
|---|---|
| `01-platform-governor.md` | Enforces constitution, CTA map, component canon |
| `02-shell-template-agent.md` | Audits and fixes shell structure |
| `03-design-system-primitives-agent.md` | Normalizes shared primitives |
| `04-overview-family-agent.md` | Aligns overview page family |
| `05-detail-family-agent.md` | Aligns detail page family |
| `06-planner-safety-agent.md` | Guards planner/booking domain integrity |
| `07-omdb-woo-boundary-agent.md` | Enforces OMDB and Woo boundaries |
| `08-mobile-regression-qa-agent.md` | Mobile and regression checks |
| `09-design-system-truth-agent.md` | Design system runtime enforcement |
| `10-csot-omdb-review-agent.md` | Final cross-layer review |

These documents are authoritative unless formally updated.

---

## 7. What requires governance review

Governance review is mandatory for:
- public page redesigns
- shell/template changes
- component system changes
- CTA changes
- dark/light system changes
- planner/cart/checkout surface changes
- account/portal/tour surface changes
- any release affecting customer-facing booking flow
- any change touching OMDB or Woo boundaries
- any release marked as launch-critical

Minor typo/content fixes may be excluded if no architecture, UX, visual, or flow logic is affected.

---

## 8. Governance process

### 8.1 Intake
A change is proposed with:
- purpose
- scope
- target page family
- risks
- expected business value

### 8.2 Classification
The change is classified as:
- low risk
- medium risk
- high risk
- release critical

### 8.3 Review
The required review loop is applied.

### 8.4 Gate decision
The release is:
- approved
- approved with warnings
- blocked
- approved as temporary exception

### 8.5 Release
Only approved changes proceed to live release.

### 8.6 Post-release review
The release is checked for:
- incidents
- regressions
- KPI movement
- unresolved risks

---

## 9. Governance review cadence

### Weekly
- implementation review
- blockers review
- launch board update

### Monthly
- governance dashboard review
- release quality review
- architecture drift review
- KPI review
- outstanding exceptions review

### Every 90 days
- strategic governance effectiveness review
- baseline vs current incident review
- planner/activities conversion review
- policy improvement review

---

## 10. Exception handling

If a release does not pass all gates but must still go live, a formal exception is required.

### Exception must include
- exact failed gate(s)
- reason for exception
- business impact if not released
- risk accepted by whom
- mitigation plan
- rollback plan
- expiry date of exception
- mandatory follow-up fix

No exception may be indefinite.

---

## 11. Blocking conditions

A release is blocked if any of the following are true:
- shell integrity is broken
- CTA role is clearly wrong on a key public page
- public page family behavior violates the constitution
- OMDB meaning is changed without approval
- Woo pricing/booking truth is endangered
- planner continuity is broken
- add-to-day handoff is broken
- checkout/cart execution is unsafe
- design system truth is replaced by local drift
- high-risk regression remains unresolved

---

## 12. Required outputs per governed release

Every governed release must have:
- release description
- affected scope
- gate outcomes
- blocker/warning list
- launch readiness status
- rollback owner
- review owner
- post-release check owner

---

## 13. Governance success measures

Governance is working if:
- release-related incidents decrease
- gate completion reaches 100%
- design drift decreases
- shell regressions decrease
- planner/add-to-day regressions decrease
- launch readiness becomes more predictable
- conversion on planner and activities flows improves

---

## 14. Final law

No release is considered ready because it “looks better”.
A release is ready only when:
- governance docs are respected
- required gates pass
- truth boundaries are protected
- launch risks are understood
- the platform is stronger after the release than before it
